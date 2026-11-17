<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationSnapshot;
use App\Pricing\PricingCalculator;
use App\Pricing\TokenRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one place a generation's status and money change.
 *
 * Every report about a generation goes through here: the create response, a poll and every
 * webhook. Each runs in a transaction that locks the generation's row, and a report is only
 * applied if it moves the generation forward. Duplicate, late and out-of-order reports change
 * nothing, so every path ends in the same state, charged exactly once.
 */
final readonly class ApplyGenerationSnapshot
{
    public function __construct(
        private PricingCalculator $calculator,
    ) {}

    /**
     * Apply what Pixelforge says about the generation. Never call this while waiting on HTTP:
     * fetch first, then apply, so the row lock is held for milliseconds.
     */
    public function handle(Generation $generation, GenerationSnapshot $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->provider_id !== null && $locked->provider_id !== $snapshot->id) {
                Log::error('Ignored a report about a different Pixelforge generation.', [
                    'generation' => $locked->id,
                    'linked_to' => $locked->provider_id,
                    'report_for' => $snapshot->id,
                ]);

                return $locked;
            }

            if (! $locked->status->canMoveTo($snapshot->status)) {
                return $locked;
            }

            $locked->provider_id = $snapshot->id;
            $locked->status = $snapshot->status;
            $locked->rates = $snapshot->rates ?? $locked->rates;
            $locked->started_at = $snapshot->startedAt ?? $locked->started_at;
            $locked->completed_at = $snapshot->completedAt ?? $locked->completed_at;
            $locked->image_url = $snapshot->imageUrl;
            $locked->image_url_expires_at = $snapshot->imageUrlExpiresAt;
            $locked->waiting_reason = null; // Pixelforge has it now, so it's no longer waiting on us
            $locked->last_synced_at = now();

            if ($snapshot->status === GenerationStatus::Succeeded) {
                $this->recordCost($locked, $snapshot);
            } elseif ($snapshot->status === GenerationStatus::Failed) {
                $this->recordFailure($locked, $snapshot->errorCode, $snapshot->errorMessage);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Fail a generation that Pixelforge refused before accepting it (content policy, daily
     * limit, ...). Once Pixelforge has the generation, only Pixelforge's reports can end it.
     */
    public function failBeforeAccepted(Generation $generation, string $errorCode, string $errorMessage): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage): Generation {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->status !== GenerationStatus::Pending) {
                return $locked;
            }

            $locked->status = GenerationStatus::Failed;
            $this->recordFailure($locked, $errorCode, $errorMessage);
            $locked->save();

            return $locked;
        });
    }

    private function recordCost(Generation $generation, GenerationSnapshot $snapshot): void
    {
        if ($snapshot->usage === null || $generation->rates === null) {
            throw new InvalidArgumentException("Pixelforge reported {$snapshot->id} as succeeded without usage or rates.");
        }

        // Billed at the rates fixed on this generation, never at today's prices.
        $cost = $this->calculator->cost($snapshot->usage, TokenRates::fromArray($generation->rates));
        $charge = $this->calculator->charge($cost);

        // The user was promised they'd never pay more than the quoted maximum. Only possible
        // if Pixelforge changed its rates between our quote and the submit; we absorb it.
        if ($charge > $generation->max_charge_nano) {
            Log::warning('Capped a charge at the maximum quoted to the user.', [
                'generation' => $generation->id,
                'charge_nano' => $charge,
                'max_charge_nano' => $generation->max_charge_nano,
            ]);
            $charge = $generation->max_charge_nano;
        }

        $generation->input_text_tokens = $snapshot->usage->inputText;
        $generation->input_image_tokens = $snapshot->usage->inputImage;
        $generation->output_tokens = $snapshot->usage->output;
        $generation->reasoning_tokens = $snapshot->usage->reasoning;
        $generation->provider_cost_nano = $cost;
        $generation->charge_nano = $charge;
    }

    private function recordFailure(Generation $generation, ?string $errorCode, ?string $errorMessage): void
    {
        $generation->provider_cost_nano = 0;
        $generation->charge_nano = 0;
        $generation->error_code = $errorCode;
        $generation->error_message = $errorMessage;
    }
}
