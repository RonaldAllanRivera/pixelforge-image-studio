<?php

namespace App\Pixelforge;

use App\Pricing\TokenUsage;
use App\Support\Nanodollars;
use InvalidArgumentException;

/**
 * What Pixelforge counted for one day, or for a whole range: succeeded generations, the
 * tokens they used and what they cost us, in exact nanodollars.
 */
final readonly class UsageFigures
{
    public function __construct(
        public int $generations,
        public TokenUsage $tokens,
        public int $costNano,
    ) {}

    /**
     * Read one `data` day or the `total` of a GET /v1/usage answer.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidArgumentException when a figure is missing or isn't a number
     */
    public static function fromArray(array $row): self
    {
        $generations = $row['generations'] ?? null;
        $cost = $row['cost_usd'] ?? null;

        if (! is_int($generations) || $generations < 0) {
            throw new InvalidArgumentException('Pixelforge usage [generations] is missing or not a whole number.');
        }

        if (! is_string($cost)) {
            throw new InvalidArgumentException('Pixelforge usage [cost_usd] is missing or not a decimal string.');
        }

        return new self($generations, TokenUsage::fromArray($row), Nanodollars::fromDecimal($cost));
    }

    /**
     * A day with no usage.
     */
    public static function zero(): self
    {
        return new self(0, new TokenUsage(0, 0, 0, 0), 0);
    }
}
