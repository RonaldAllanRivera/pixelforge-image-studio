<?php

namespace App\Reports;

use App\Pixelforge\UsageFigures;

/**
 * One UTC day of our succeeded generations: what Pixelforge should have counted (generations,
 * tokens, cost), and what we charged the users for them.
 */
final readonly class SpendRow
{
    public function __construct(
        public UsageFigures $usage,
        public int $chargedNano,
    ) {}
}
