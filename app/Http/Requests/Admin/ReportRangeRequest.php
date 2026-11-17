<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The UTC days the admin report covers, with Pixelforge's own rules for GET /v1/usage:
 * by default the last 30 days ending today, at most 92 days, and nothing after today.
 */
class ReportRangeRequest extends FormRequest
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 92;

    /**
     * The route allows admins only (the view-admin-report gate).
     */
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
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = (int) $this->start()->diffInDays($this->end(), false) + 1;

                if ($days < 1) {
                    $validator->errors()->add('start', 'The start date must be on or before the end date.');
                } elseif ($days > self::MAX_DAYS) {
                    $validator->errors()->add('start', 'Choose at most '.self::MAX_DAYS.' days. Pixelforge reports no more at once.');
                }
            },
        ];
    }

    /**
     * The first day, at 00:00 UTC.
     */
    public function start(): CarbonImmutable
    {
        return $this->day('start') ?? $this->end()->subDays(self::DEFAULT_DAYS - 1);
    }

    /**
     * The last day, at 00:00 UTC. Today unless given.
     */
    public function end(): CarbonImmutable
    {
        return $this->day('end') ?? $this->today();
    }

    private function day(string $key): ?CarbonImmutable
    {
        $value = $this->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC') ?: null;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
