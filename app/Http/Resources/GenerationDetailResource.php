<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\TokenRates;
use App\Support\Nanodollars;
use Illuminate\Http\Request;

/**
 * A generation with the full cost math, for its detail page: each token type estimated,
 * at most and actually used, at the rates Pixelforge fixed on this generation.
 */
class GenerationDetailResource extends GenerationResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $generation = $this->generation();

        return [
            ...parent::toArray($request),
            'provider_id' => $generation->provider_id,
            'submit_attempts' => $generation->submit_attempts,
            'tokens' => $this->tokens($generation),
            'totals' => $this->totals($generation),
        ];
    }

    /**
     * @return list<array{label: string, estimated: int|null, maximum: int|null, actual: int|null, rate: string|null, cost: string|null}>
     */
    private function tokens(Generation $generation): array
    {
        // Known once Pixelforge accepts the generation.
        $rates = $generation->rates === null ? null : TokenRates::fromArray($generation->rates);

        // [label, key in the quoted usage, tokens actually used, nanodollars per token]
        $types = [
            ['Text', 'input_text_tokens', $generation->input_text_tokens, $rates?->inputText],
            ['Reference images', 'input_image_tokens', $generation->input_image_tokens, $rates?->inputImage],
            ['Image output', 'output_tokens', $generation->output_tokens, $rates?->output],
            ['Reasoning', 'reasoning_tokens', $generation->reasoning_tokens, $rates?->reasoning],
        ];

        return array_map(fn (array $type) => [
            'label' => $type[0],
            'estimated' => $generation->quoted_usage['estimated'][$type[1]] ?? null,
            'maximum' => $generation->quoted_usage['maximum'][$type[1]] ?? null,
            'actual' => $type[2],
            // Shown per 1M tokens, the way Pixelforge publishes its prices.
            'rate' => $type[3] === null ? null : Nanodollars::format($type[3] * 1_000_000, 2),
            'cost' => $type[2] === null || $type[3] === null ? null : Nanodollars::format($type[2] * $type[3], 9),
        ], $types);
    }

    /**
     * @return array{provider_cost: string, fee: string, charge: string}|null
     */
    private function totals(Generation $generation): ?array
    {
        if ($generation->status !== GenerationStatus::Succeeded
            || $generation->provider_cost_nano === null
            || $generation->charge_nano === null) {
            return null;
        }

        return [
            'provider_cost' => Nanodollars::format($generation->provider_cost_nano, 9),
            'fee' => Nanodollars::format($generation->charge_nano - $generation->provider_cost_nano, 9),
            'charge' => Nanodollars::format($generation->charge_nano, 9),
        ];
    }
}
