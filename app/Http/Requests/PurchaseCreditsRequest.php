<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A pretend credit purchase: one of a few fixed amounts, in whole dollars.
 */
class PurchaseCreditsRequest extends FormRequest
{
    /** The amounts on offer, in US dollars. */
    public const AMOUNTS = [5, 10, 25];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', Rule::in(self::AMOUNTS)],
        ];
    }

    public function amountNano(): int
    {
        return (int) $this->validated('amount') * 1_000_000_000;
    }
}
