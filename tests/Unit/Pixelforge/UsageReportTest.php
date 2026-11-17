<?php

use App\Pixelforge\Exceptions\OutcomeUnknown;
use App\Pixelforge\UsageFigures;
use App\Pixelforge\UsageReport;
use App\Pricing\TokenUsage;
use Tests\Fixtures\Pixelforge;

test("reads Pixelforge's usage exactly", function () {
    $report = UsageReport::fromArray(Pixelforge::load('usage'));

    expect($report->day('2026-11-17'))->toEqual(new UsageFigures(4, new TokenUsage(50, 2295, 3310, 2116), 182_050_000))
        ->and($report->day('2026-11-16')->costNano)->toBe(17_250_000)
        ->and($report->total->costNano)->toBe(199_300_000)
        ->and($report->total->generations)->toBe(5)
        ->and(array_keys($report->days))->toBe(['2026-11-16', '2026-11-17', '2026-11-18']);
});

test("a day Pixelforge didn't list counts as zero", function () {
    $report = UsageReport::fromArray(Pixelforge::load('usage'));

    expect($report->day('2026-12-01'))->toEqual(UsageFigures::zero());
});

test("refuses a body that isn't a usage report", function (Closure $body) {
    expect(fn () => UsageReport::fromArray($body(Pixelforge::load('usage'))))->toThrow(OutcomeUnknown::class);
})->with([
    'an empty body' => fn (array $usage) => [],
    'no data' => fn (array $usage) => ['object' => 'usage'],
    'no total' => function (array $usage) {
        unset($usage['total']);

        return $usage;
    },
    'a day without a date' => function (array $usage) {
        unset($usage['data'][0]['date']);

        return $usage;
    },
    'a day without a cost' => function (array $usage) {
        unset($usage['data'][1]['cost_usd']);

        return $usage;
    },
    'a cost that is not a number' => function (array $usage) {
        $usage['data'][0]['cost_usd'] = 'abc';

        return $usage;
    },
    'a count that is not a whole number' => function (array $usage) {
        $usage['total']['generations'] = '5';

        return $usage;
    },
]);
