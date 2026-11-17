<?php

namespace App\Pricing;

/**
 * What a generation should cost before it runs. All amounts are in nanodollars.
 *
 * "Cost" is what Pixelforge charges us. "Charge" is what the user pays: cost plus markup.
 * The estimate is the likely price; the maximum is the most the user can ever pay.
 */
final readonly class Quote
{
    public function __construct(
        public TokenUsage $estimatedUsage,
        public TokenUsage $maximumUsage,
        public int $estimatedCost,
        public int $estimatedCharge,
        public int $maximumCost,
        public int $maximumCharge,
    ) {}
}
