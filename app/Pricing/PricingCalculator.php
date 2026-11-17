<?php

namespace App\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Nanodollars;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * Prices generations. Pure arithmetic on whole nanodollars: no floats, no I/O.
 */
final readonly class PricingCalculator
{
    public function __construct(
        #[Config('pricing.markup_percent')]
        private int $markupPercent,
    ) {}

    /**
     * What Pixelforge charges for this usage: tokens × rate for each token type, summed.
     * Exact, because every rate is a whole number of nanodollars per token.
     */
    public function cost(TokenUsage $usage, TokenRates $rates): int
    {
        return $usage->inputText * $rates->inputText
            + $usage->inputImage * $rates->inputImage
            + $usage->output * $rates->output
            + $usage->reasoning * $rates->reasoning;
    }

    /**
     * What the user pays for a provider cost: the cost plus our markup, rounded up.
     */
    public function charge(int $cost): int
    {
        return Nanodollars::addMarkup($cost, $this->markupPercent);
    }

    /**
     * Price a generation before it runs, from what is known up front.
     *
     * Image and output tokens are exact. Text tokens are estimated from the prompt length
     * (Pixelforge's real count is within ±15%), and reasoning tokens are only known afterwards.
     */
    public function quote(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImages, PriceSheet $sheet): Quote
    {
        if ($referenceImages < 0) {
            throw new InvalidArgumentException('The number of reference images cannot be negative.');
        }

        $textTokens = $this->estimatedTextTokens($prompt, $sheet);
        $imageTokens = $referenceImages * $sheet->tokensPerReferenceImage;
        $outputTokens = $sheet->outputTokens($quality, $size);
        $minReasoning = $sheet->minReasoningTokens($quality);
        $maxReasoning = $sheet->maxReasoningTokens($quality);

        $estimatedUsage = new TokenUsage(
            inputText: $textTokens,
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: intdiv($minReasoning + $maxReasoning, 2),
        );

        $maximumUsage = new TokenUsage(
            inputText: $this->maximumTextTokens($textTokens, $sheet),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $maxReasoning,
        );

        $estimatedCost = $this->cost($estimatedUsage, $sheet->rates);
        $maximumCost = $this->cost($maximumUsage, $sheet->rates);

        return new Quote(
            estimatedUsage: $estimatedUsage,
            maximumUsage: $maximumUsage,
            estimatedCost: $estimatedCost,
            estimatedCharge: $this->charge($estimatedCost),
            maximumCost: $maximumCost,
            maximumCharge: $this->charge($maximumCost),
        );
    }

    /**
     * Pixelforge's estimate: one token per 4 characters, rounded up. Characters are Unicode
     * characters, so an emoji counts once, not once per byte.
     */
    private function estimatedTextTokens(string $prompt, PriceSheet $sheet): int
    {
        $characters = mb_strlen($prompt, 'UTF-8');

        return intdiv($characters + $sheet->charactersPerTextToken - 1, $sheet->charactersPerTextToken);
    }

    /**
     * The most text tokens Pixelforge can count for this prompt: the estimate plus the
     * published variance (15%), rounded up.
     */
    private function maximumTextTokens(int $estimatedTextTokens, PriceSheet $sheet): int
    {
        return BigDecimal::of($estimatedTextTokens)
            ->multipliedBy($sheet->textTokenVariance->plus(1))
            ->toScale(0, RoundingMode::Up)
            ->toInt();
    }
}
