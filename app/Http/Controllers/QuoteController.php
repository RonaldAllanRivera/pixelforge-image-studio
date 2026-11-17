<?php

namespace App\Http\Controllers;

use App\Credits\CreditLedger;
use App\Enums\ImageQuality;
use App\Http\Requests\QuoteRequest;
use App\Models\User;
use App\Pricing\CurrentPrices;
use App\Pricing\PricingCalculator;
use App\Pricing\Quote;
use App\Pricing\TokenRates;
use App\Support\Nanodollars;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * The live price on the Studio form. It quotes all three qualities at once, so each
 * quality button can show its own price, and breaks down the one the user picked.
 * Characters are counted here, as Pixelforge counts them (emoji count as one).
 */
final class QuoteController extends Controller
{
    public function __invoke(QuoteRequest $request, PricingCalculator $calculator, CurrentPrices $prices, CreditLedger $ledger, #[CurrentUser] User $user): JsonResponse
    {
        $sheet = $prices->sheet();
        $balance = $ledger->balance($user);
        $prompt = $request->prompt();

        $quotes = [];

        foreach (ImageQuality::cases() as $quality) {
            $quotes[$quality->value] = $calculator->quote($prompt, $request->imageSize(), $quality, $request->referenceImageCount(), $sheet);
        }

        return response()->json([
            'characters' => mb_strlen($prompt),
            'balance' => Nanodollars::formatRoundedDown($balance),
            'qualities' => array_map(fn (Quote $quote) => [
                'estimated' => Nanodollars::format($quote->estimatedCharge),
                'maximum' => Nanodollars::formatAtMost($quote->maximumCharge),
                // Decided here, in whole nanodollars, so the browser never does money math.
                // CreateGeneration checks again under a lock; this only sets the button.
                'affordable' => $balance >= $quote->maximumCharge,
            ], $quotes),
            'breakdown' => $this->breakdown($quotes[$request->imageQuality()->value], $sheet->rates),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdown(Quote $quote, TokenRates $rates): array
    {
        $estimated = $quote->estimatedUsage;
        $maximum = $quote->maximumUsage;

        // [label, estimated tokens, maximum tokens, nanodollars per token]
        $types = [
            ['Text', $estimated->inputText, $maximum->inputText, $rates->inputText],
            ['Reference images', $estimated->inputImage, $maximum->inputImage, $rates->inputImage],
            ['Image output', $estimated->output, $maximum->output, $rates->output],
            ['Reasoning', $estimated->reasoning, $maximum->reasoning, $rates->reasoning],
        ];

        return [
            'rows' => array_map(fn (array $type) => [
                'label' => $type[0],
                'estimated_tokens' => $type[1],
                'maximum_tokens' => $type[2],
                // Per 1M tokens, the way Pixelforge publishes its prices.
                'rate' => Nanodollars::format($type[3] * 1_000_000, 2),
            ], $types),
            'estimated_cost' => Nanodollars::format($quote->estimatedCost, 9),
            'maximum_cost' => Nanodollars::format($quote->maximumCost, 9),
            'estimated_fee' => Nanodollars::format($quote->estimatedCharge - $quote->estimatedCost, 9),
            'maximum_fee' => Nanodollars::format($quote->maximumCharge - $quote->maximumCost, 9),
            'estimated_charge' => Nanodollars::format($quote->estimatedCharge, 9),
            'maximum_charge' => Nanodollars::format($quote->maximumCharge, 9),
        ];
    }
}
