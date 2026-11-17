<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * What one token of each type costs, in whole nanodollars.
 *
 * Pixelforge publishes rates in USD per 1M tokens ("12.50"). $1 per 1M tokens is
 * 1,000 nanodollars per token, so a rate with up to 3 decimals always gives a whole
 * number of nanodollars per token, and a cost never needs rounding.
 */
final readonly class TokenRates
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * Build from Pixelforge's `rates` object, as returned by GET /v1/pricing or on a generation.
     *
     * @param  array<string, mixed>  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: self::nanodollarsPerToken($rates, 'input_text_tokens'),
            inputImage: self::nanodollarsPerToken($rates, 'input_image_tokens'),
            output: self::nanodollarsPerToken($rates, 'output_tokens'),
            reasoning: self::nanodollarsPerToken($rates, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private static function nanodollarsPerToken(array $rates, string $type): int
    {
        $rate = $rates[$type] ?? null;

        if (! is_string($rate) && ! is_int($rate)) {
            throw new InvalidArgumentException("Pixelforge rate [{$type}] is missing.");
        }

        try {
            // toBigInteger() refuses to drop a fraction, so a sub-nanodollar rate throws here.
            $perToken = BigDecimal::of($rate)->multipliedBy(1_000)->toBigInteger()->toInt();
        } catch (MathException $e) {
            throw new InvalidArgumentException("Pixelforge rate [{$type}] of [{$rate}] is not a whole number of nanodollars per token.", previous: $e);
        }

        if ($perToken < 0) {
            throw new InvalidArgumentException("Pixelforge rate [{$type}] of [{$rate}] is negative.");
        }

        return $perToken;
    }
}
