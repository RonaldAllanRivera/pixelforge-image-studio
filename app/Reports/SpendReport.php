<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Pixelforge\UsageFigures;
use App\Pricing\TokenUsage;
use App\Support\MaskedEmail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What users were charged and what Pixelforge cost us, from our own records, grouped the
 * way Pixelforge's GET /v1/usage groups: only succeeded generations, each on the UTC day it
 * finished (Pixelforge's completed_at, stored in UTC).
 */
final readonly class SpendReport
{
    /**
     * Every day from start to end, both included, even days with nothing.
     *
     * @return array<string, SpendRow> Keyed by date (Y-m-d), oldest first.
     */
    public function byDay(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->succeededBetween($start, $end)
            ->selectRaw("to_char(completed_at, 'YYYY-MM-DD') as day")
            ->selectRaw('count(*) as generations')
            ->selectRaw('coalesce(sum(input_text_tokens), 0) as input_text_tokens')
            ->selectRaw('coalesce(sum(input_image_tokens), 0) as input_image_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens')
            ->selectRaw('coalesce(sum(reasoning_tokens), 0) as reasoning_tokens')
            ->selectRaw('coalesce(sum(provider_cost_nano), 0) as cost')
            ->selectRaw('coalesce(sum(charge_nano), 0) as charged')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $row = $rows->get($date);

            $days[$date] = $row === null
                ? new SpendRow(UsageFigures::zero(), 0)
                : new SpendRow(
                    new UsageFigures(
                        (int) $row->generations,
                        new TokenUsage((int) $row->input_text_tokens, (int) $row->input_image_tokens, (int) $row->output_tokens, (int) $row->reasoning_tokens),
                        (int) $row->cost,
                    ),
                    (int) $row->charged,
                );
        }

        return $days;
    }

    /**
     * Each account's totals for the range, the biggest spender first. Generations whose
     * account was deleted are grouped as one "Deleted account".
     *
     * @return list<UserSpendRow>
     */
    public function byUser(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->succeededBetween($start, $end)
            ->leftJoin('users', 'users.id', '=', 'generations.user_id')
            ->groupBy('generations.user_id', 'users.name', 'users.email')
            ->select('generations.user_id', 'users.name', 'users.email')
            ->selectRaw('count(*) as generations')
            ->selectRaw('coalesce(sum(generations.charge_nano), 0) as charged')
            ->selectRaw('coalesce(sum(generations.provider_cost_nano), 0) as cost')
            ->orderByDesc('charged')
            ->orderBy('generations.user_id')
            ->get()
            ->all();

        return array_values(array_map(fn (object $row) => new UserSpendRow(
            userId: $row->user_id === null ? null : (int) $row->user_id,
            name: $row->user_id === null ? 'Deleted account' : (string) $row->name,
            maskedEmail: $row->user_id === null ? null : MaskedEmail::mask((string) $row->email),
            generations: (int) $row->generations,
            chargedNano: (int) $row->charged,
            costNano: (int) $row->cost,
        ), $rows));
    }

    /**
     * Succeeded generations that finished from start 00:00 up to (not including) the day
     * after end, 00:00, UTC.
     */
    private function succeededBetween(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('generations')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->where('generations.completed_at', '>=', $start->startOfDay()->format('Y-m-d H:i:s'))
            ->where('generations.completed_at', '<', $end->addDay()->startOfDay()->format('Y-m-d H:i:s'));
    }
}
