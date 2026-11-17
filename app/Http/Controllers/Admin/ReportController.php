<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRangeRequest;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\UsageFigures;
use App\Pricing\TokenUsage;
use App\Reports\SpendReport;
use App\Reports\SpendRow;
use App\Reports\UsageComparison;
use App\Reports\UserSpendRow;
use App\Support\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operators' report: what users were charged, what Pixelforge cost us and the profit,
 * for each UTC day and each user. Amounts show all 9 decimals, so they can be checked
 * against Pixelforge's cost_usd by eye.
 *
 * Each day is also checked against Pixelforge's own GET /v1/usage. That part loads after
 * the page (a deferred prop), so our numbers show at once and still show if Pixelforge
 * can't be reached. It's fetched fresh on every load: nothing is cached or scheduled.
 */
final class ReportController extends Controller
{
    public const UNAVAILABLE = "Couldn't reach Pixelforge to compare. Reload to try again.";

    /**
     * Pixelforge allows 60 requests a minute per account. The jobs get 50 of them (the
     * "pixelforge" limiter); the report gets 5, shared by every admin, so reloading it can
     * never hold up anyone's images.
     */
    public const COMPARISONS_PER_MINUTE = 5;

    public const LIMITED = 'Pixelforge comparisons are limited to 5 a minute. Reload in a minute to compare again.';

    public function __invoke(ReportRangeRequest $request, SpendReport $report, PixelforgeClient $pixelforge): Response
    {
        $start = $request->start();
        $end = $request->end();
        $days = $report->byDay($start, $end);
        $total = $this->total($days);

        return Inertia::render('admin/Report', [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'max_days' => ReportRangeRequest::MAX_DAYS,
            ],
            // Newest first.
            'days' => array_reverse(array_map(
                fn (string $date, SpendRow $row) => ['date' => $date, ...$this->amounts($row->usage->generations, $row->chargedNano, $row->usage->costNano)],
                array_keys($days),
                array_values($days),
            )),
            'users' => array_map(fn (UserSpendRow $row) => [
                'name' => $row->name,
                'email' => $row->maskedEmail,
                ...$this->amounts($row->generations, $row->chargedNano, $row->costNano),
            ], $report->byUser($start, $end)),
            'totals' => $this->amounts($total->usage->generations, $total->chargedNano, $total->usage->costNano),
            'pixelforge' => Inertia::defer(fn () => $this->compare($pixelforge, $start, $end, $days, $total)),
        ]);
    }

    /**
     * Each day, and the whole range, against Pixelforge's GET /v1/usage.
     *
     * @param  array<string, SpendRow>  $days
     * @return array<string, mixed>
     */
    private function compare(PixelforgeClient $pixelforge, CarbonImmutable $start, CarbonImmutable $end, array $days, SpendRow $total): array
    {
        if (RateLimiter::tooManyAttempts('pixelforge-report', self::COMPARISONS_PER_MINUTE)) {
            return ['available' => false, 'message' => self::LIMITED];
        }

        RateLimiter::hit('pixelforge-report', 60);

        try {
            $usage = $pixelforge->usage($start, $end);
        } catch (PixelforgeException $e) {
            Log::warning("Couldn't fetch Pixelforge's usage for the admin report.", [
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ]);

            return ['available' => false, 'message' => self::UNAVAILABLE];
        }

        $compared = [];

        foreach ($days as $date => $row) {
            $compared[$date] = $this->comparison($row->usage, $usage->day($date));
        }

        return [
            'available' => true,
            'days' => $compared,
            'total' => $this->comparison($total->usage, $usage->total),
            'matching_days' => count(array_filter($compared, fn (array $day) => $day['matches'])),
            'day_count' => count($compared),
        ];
    }

    /**
     * @return array{generations: int, cost: string, matches: bool, differences: list<string>}
     */
    private function comparison(UsageFigures $ours, UsageFigures $theirs): array
    {
        $differences = UsageComparison::differences($ours, $theirs);

        return [
            'generations' => $theirs->generations,
            'cost' => Nanodollars::format($theirs->costNano, 9),
            'matches' => $differences === [],
            'differences' => $differences,
        ];
    }

    /**
     * The whole range, added up from the days.
     *
     * @param  array<string, SpendRow>  $days
     */
    private function total(array $days): SpendRow
    {
        $sum = fn (callable $figure): int => array_sum(array_map($figure, $days));

        return new SpendRow(
            new UsageFigures(
                $sum(fn (SpendRow $row) => $row->usage->generations),
                new TokenUsage(
                    $sum(fn (SpendRow $row) => $row->usage->tokens->inputText),
                    $sum(fn (SpendRow $row) => $row->usage->tokens->inputImage),
                    $sum(fn (SpendRow $row) => $row->usage->tokens->output),
                    $sum(fn (SpendRow $row) => $row->usage->tokens->reasoning),
                ),
                $sum(fn (SpendRow $row) => $row->usage->costNano),
            ),
            $sum(fn (SpendRow $row) => $row->chargedNano),
        );
    }

    /**
     * @return array{generations: int, charged: string, cost: string, profit: string}
     */
    private function amounts(int $generations, int $chargedNano, int $costNano): array
    {
        return [
            'generations' => $generations,
            'charged' => Nanodollars::format($chargedNano, 9),
            'cost' => Nanodollars::format($costNano, 9),
            'profit' => Nanodollars::format($chargedNano - $costNano, 9),
        ];
    }
}
