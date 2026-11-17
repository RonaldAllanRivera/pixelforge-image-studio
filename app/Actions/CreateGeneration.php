<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\CurrentPrices;
use App\Pricing\PricingCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saves a user's new generation with its price quote, then sends it to Pixelforge in the
 * background. The user sees it in their history at once, as pending.
 */
final readonly class CreateGeneration
{
    public function __construct(
        private CurrentPrices $prices,
        private PricingCalculator $calculator,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     */
    public function handle(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages): Generation
    {
        // Priced before the transaction: fetching prices may call Pixelforge.
        $quote = $this->calculator->quote($prompt, $size, $quality, count($referenceImages), $this->prices->sheet());

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $quote): Generation {
            $generation = $user->generations()->create([
                // Sent with every attempt, so retries can never create a second generation.
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_cost_nano' => $quote->estimatedCost,
                'estimated_charge_nano' => $quote->estimatedCharge,
                'max_cost_nano' => $quote->maximumCost,
                'max_charge_nano' => $quote->maximumCharge,
            ]);

            // After the commit, so the worker can never start before the row exists.
            SubmitGeneration::dispatch($generation)->afterCommit();

            return $generation;
        });
    }
}
