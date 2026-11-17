<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the Studio's history shows it. Every amount is formatted here, on the
 * server, so the browser only displays text and never does money math.
 */
class GenerationResource extends JsonResource
{
    /**
     * Polling gives up after about 15 minutes (16 polls), and a submit retries for 30 but
     * touches the row on every wait. An unfinished row with no news for this long has no job
     * working on it; only the hourly reconcile checks it. So the page stops polling it.
     */
    public const STALLED_AFTER_MINUTES = 15;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $generation = $this->generation();
        $imageExpired = $generation->image_url !== null && $generation->image_url_expires_at?->isPast() === true;

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'size_label' => $generation->size->label(),
            'quality' => $generation->quality->value,
            'quality_label' => $generation->quality->label(),
            'reference_images' => $generation->reference_images,
            'status' => $generation->status->value,
            'status_label' => $this->statusLabel($generation),
            'is_final' => $generation->status->isFinal(),
            'is_stalled' => $this->isStalled($generation),
            // Pixelforge's image links expire; show a placeholder rather than a broken image.
            'image_url' => $imageExpired ? null : $generation->image_url,
            'image_expired' => $imageExpired,
            'failure_reason' => $generation->status === GenerationStatus::Failed
                ? $this->failureReason($generation->error_code)
                : null,
            'estimated_charge' => Nanodollars::format($generation->estimated_charge_nano),
            'maximum_charge' => Nanodollars::formatAtMost($generation->max_charge_nano),
            'actual_charge' => $this->actualCharge($generation),
            'created_at' => $generation->created_at?->toIso8601String(),
            'started_at' => $generation->started_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }

    protected function generation(): Generation
    {
        assert($this->resource instanceof Generation);

        return $this->resource;
    }

    private function isStalled(Generation $generation): bool
    {
        return ! $generation->status->isFinal()
            && $generation->updated_at?->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES)) === true;
    }

    private function statusLabel(Generation $generation): string
    {
        return match ($generation->status) {
            GenerationStatus::Pending => $generation->waiting_reason?->label() ?? 'Submitting',
            GenerationStatus::Queued => 'Queued',
            GenerationStatus::Processing => 'Generating',
            GenerationStatus::Succeeded => 'Succeeded',
            GenerationStatus::Failed => 'Failed',
        };
    }

    /**
     * Plain words for the user. Pixelforge's raw message stays in the database for us.
     */
    private function failureReason(?string $errorCode): string
    {
        return match ($errorCode) {
            'content_policy_violation' => "Pixelforge's content policy blocked this prompt.",
            'daily_limit_exceeded' => "Pixelforge's daily limit is reached. Try again after midnight UTC.",
            'overloaded' => 'Pixelforge was too busy to take it, even after 5 tries.',
            'generation_failed' => "Pixelforge couldn't generate this image.",
            default => 'Something went wrong while creating this image.',
        };
    }

    private function actualCharge(Generation $generation): ?string
    {
        return match (true) {
            $generation->status === GenerationStatus::Failed => 'Not charged',
            $generation->status === GenerationStatus::Succeeded && $generation->charge_nano !== null => Nanodollars::format($generation->charge_nano),
            default => null,
        };
    }
}
