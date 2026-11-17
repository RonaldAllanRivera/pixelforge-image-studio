<?php

namespace App\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Everything needed to price a generation before it runs: the token rates and the
 * token rules, as published by Pixelforge's GET /v1/pricing.
 */
final readonly class PriceSheet
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  by quality value, then size value
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  by quality value
     */
    public function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public BigDecimal $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $response  The body of GET /v1/pricing.
     */
    public static function fromPricingResponse(array $response): self
    {
        $outputTokens = [];
        $reasoningTokens = [];

        foreach (ImageQuality::cases() as $quality) {
            foreach (ImageSize::cases() as $size) {
                $outputTokens[$quality->value][$size->value] = self::wholeNumber($response, "tokens.output.{$quality->value}.{$size->value}");
            }

            $reasoningTokens[$quality->value] = [
                'min' => self::wholeNumber($response, "tokens.reasoning.{$quality->value}.min"),
                'max' => self::wholeNumber($response, "tokens.reasoning.{$quality->value}.max"),
            ];
        }

        $rates = Arr::get($response, 'rates');
        $variance = Arr::get($response, 'tokens.text_token_variance');

        if (! is_array($rates) || ! is_numeric($variance)) {
            throw new InvalidArgumentException('Pixelforge pricing is missing [rates] or [tokens.text_token_variance].');
        }

        return new self(
            rates: TokenRates::fromArray($rates),
            charactersPerTextToken: self::wholeNumber($response, 'tokens.characters_per_text_token'),
            textTokenVariance: BigDecimal::of((string) $variance),
            tokensPerReferenceImage: self::wholeNumber($response, 'tokens.per_reference_image'),
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
        );
    }

    /**
     * The exact output tokens for this quality and size. Known before the generation runs.
     */
    public function outputTokens(ImageQuality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    public function minReasoningTokens(ImageQuality $quality): int
    {
        return $this->reasoningTokens[$quality->value]['min'];
    }

    public function maxReasoningTokens(ImageQuality $quality): int
    {
        return $this->reasoningTokens[$quality->value]['max'];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private static function wholeNumber(array $response, string $path): int
    {
        $value = Arr::get($response, $path);

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Pixelforge pricing is missing a whole number at [{$path}].");
        }

        return $value;
    }
}
