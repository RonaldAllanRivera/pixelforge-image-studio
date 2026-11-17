<?php

namespace App\Http\Resources;

use App\Enums\CreditType;
use App\Models\CreditTransaction;
use App\Support\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One entry on the Credits page. Amounts are signed display strings ("+$10.0000",
 * "−$0.0863"), formatted here so the browser never does money math.
 */
class CreditTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CreditTransaction $entry */
        $entry = $this->resource;
        $generation = $entry->generation;

        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            ...self::amounts($entry),
            'created_at' => $entry->created_at?->toIso8601String(),
            'generation' => $generation === null ? null : [
                'id' => $generation->id,
                'prompt' => Str::limit($generation->prompt, 60),
            ],
        ];
    }

    /**
     * The entry's label and signed amount, rounded for reading and exact.
     *
     * @return array{label: string, amount: string, amount_exact: string}
     */
    public static function amounts(CreditTransaction $entry): array
    {
        // A hold and its release are the generation's maximum, so they're rounded up exactly
        // as "never more than" was quoted, and visibly cancel out. Other amounts round half up.
        $amount = abs($entry->amount_nano);
        $rounded = in_array($entry->type, [CreditType::Hold, CreditType::Release], true)
            ? Nanodollars::formatAtMost($amount)
            : Nanodollars::format($amount);

        return [
            'label' => $entry->type->label(),
            'amount' => self::sign($entry->amount_nano).$rounded,
            'amount_exact' => self::sign($entry->amount_nano).Nanodollars::format($amount, 9),
        ];
    }

    private static function sign(int $nanodollars): string
    {
        // A real minus sign (U+2212), so it lines up with the plus and reads clearly.
        return $nanodollars < 0 ? '−' : '+';
    }
}
