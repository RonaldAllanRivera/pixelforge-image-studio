<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Nanodollars;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    $this->travelTo(now()->parse('2026-11-18 12:00:00'));
    $this->admin = User::factory()->admin()->create();
});

/**
 * The report for 16–18 November, as the admin sees it.
 *
 * @return array<string, mixed>
 */
function reportProps(User $admin, array $query = ['start' => '2026-11-16', 'end' => '2026-11-18']): array
{
    return test()->actingAs($admin)->get(route('admin.report', $query))->assertOk()->inertiaProps();
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function byDate(array $rows): array
{
    return array_column($rows, null, 'date');
}

test('only admins can open the report', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/Report'));
});

test('covers the last 30 days by default', function () {
    $this->actingAs($this->admin)->get(route('admin.report'))->assertInertia(fn (Assert $page) => $page
        ->where('range.start', '2026-10-20')
        ->where('range.end', '2026-11-18')
        ->where('range.max_days', 92)
        ->has('days', 30));
});

test('refuses a range Pixelforge would refuse', function (array $query) {
    $this->actingAs($this->admin)->get(route('admin.report', $query))->assertSessionHasErrors();
})->with([
    '93 days' => [['start' => '2026-08-18', 'end' => '2026-11-18']],
    'start after end' => [['start' => '2026-11-18', 'end' => '2026-11-17']],
    'an end tomorrow' => [['start' => '2026-11-10', 'end' => '2026-11-19']],
    'a date that does not exist' => [['start' => '2026-02-30', 'end' => '2026-03-01']],
]);

test('accepts exactly 92 days', function () {
    $this->actingAs($this->admin)->get(route('admin.report', ['start' => '2026-08-19', 'end' => '2026-11-18']))
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->has('days', 92));
});

test('counts a generation on the UTC day it finished', function () {
    Generation::factory()->succeeded('2026-11-17 00:00:10')->create(['created_at' => '2026-11-16 23:50:00']);

    $days = byDate(reportProps($this->admin)['days']);

    expect($days['2026-11-17']['generations'])->toBe(1)
        ->and($days['2026-11-16']['generations'])->toBe(0);
});

test('puts the last second of a day on that day', function () {
    Generation::factory()->succeeded('2026-11-16 23:59:59')->create();
    Generation::factory()->succeeded('2026-11-17 00:00:00')->create();

    $days = byDate(reportProps($this->admin)['days']);

    expect($days['2026-11-16']['generations'])->toBe(1)
        ->and($days['2026-11-17']['generations'])->toBe(1);
});

test('counts only succeeded generations', function () {
    Generation::factory()->create(['status' => GenerationStatus::Failed, 'completed_at' => '2026-11-17 10:00:00', 'provider_cost_nano' => 0, 'charge_nano' => 0]);
    Generation::factory()->create(['status' => GenerationStatus::Processing]);
    Generation::factory()->create(['status' => GenerationStatus::Pending]);

    $props = reportProps($this->admin);

    expect(array_column($props['days'], 'generations'))->toBe([0, 0, 0])
        ->and($props['users'])->toBe([])
        ->and($props['totals']['generations'])->toBe(0);
});

test('adds up money exactly', function () {
    Generation::factory()->succeeded('2026-11-16 08:00:00')->create();
    Generation::factory()->succeeded('2026-11-16 20:00:00')->create();

    $day = byDate(reportProps($this->admin)['days'])['2026-11-16'];

    expect($day)->toBe([
        'date' => '2026-11-16',
        'generations' => 2,
        'charged' => '$0.043125000',
        'cost' => '$0.034500000',
        'profit' => '$0.008625000',
    ]);
});

test('lists every day, even empty ones, newest first', function () {
    Generation::factory()->succeeded('2026-11-17 09:00:00')->create();

    $days = reportProps($this->admin)['days'];

    expect(array_column($days, 'date'))->toBe(['2026-11-18', '2026-11-17', '2026-11-16'])
        ->and(array_column($days, 'generations'))->toBe([0, 1, 0])
        ->and($days[0]['charged'])->toBe('$0.000000000');
});

test('per-user totals equal per-day totals, with masked emails', function () {
    $allan = User::factory()->create(['name' => 'Allan', 'email' => 'allan@gmail.com']);
    $bea = User::factory()->create(['name' => 'Bea', 'email' => 'bea@example.com']);
    $gone = User::factory()->create();
    Generation::factory()->for($allan)->succeeded('2026-11-16 10:00:00')->create();
    Generation::factory()->for($allan)->succeeded('2026-11-17 10:00:00')->create();
    Generation::factory()->for($bea)->succeeded('2026-11-18 10:00:00')->create(['charge_nano' => 30_000_000]);
    Generation::factory()->for($gone)->succeeded('2026-11-17 11:00:00')->create();
    $gone->delete();

    $props = reportProps($this->admin);
    $sum = fn (array $rows, string $key): int => array_sum(array_map(fn (array $row) => Nanodollars::fromDecimal(ltrim($row[$key], '$')), $rows));

    expect(array_column($props['users'], 'name'))->toBe(['Allan', 'Bea', 'Deleted account'])
        ->and(array_column($props['users'], 'email'))->toBe(['al•••@gmail.com', 'be•••@example.com', null])
        ->and(array_column($props['users'], 'generations'))->toBe([2, 1, 1])
        ->and(json_encode($props['users']))->not->toContain('allan@gmail.com')
        ->and($props['totals'])->toBe([
            'generations' => 4,
            'charged' => '$0.094687500',
            'cost' => '$0.069000000',
            'profit' => '$0.025687500',
        ])
        ->and($sum($props['users'], 'charged'))->toBe($sum($props['days'], 'charged'))
        ->and($sum($props['users'], 'cost'))->toBe($sum($props['days'], 'cost'))
        ->and($sum($props['users'], 'charged'))->toBe(94_687_500);
});

/*
| Checked against Pixelforge: GET /v1/usage for 16–18 November (the real answer, saved in
| usage.json). Our only generation is the real generation-succeeded one, which finished at
| 2026-11-16 23:23:17 UTC: the very generation Pixelforge counts on 16 November.
*/

/**
 * The page's own props, then its deferred `pixelforge` prop, loaded the way the page loads it.
 *
 * @return array{page: array<string, mixed>, pixelforge: array<string, mixed>}
 */
function reportWithPixelforge(User $admin): array
{
    $result = [];

    test()->actingAs($admin)
        ->get(route('admin.report', ['start' => '2026-11-16', 'end' => '2026-11-18']))
        ->assertInertia(function (Assert $page) use (&$result) {
            $result['page'] = $page->toArray()['props'];

            $page->missing('pixelforge') // our numbers first; Pixelforge's a moment later
                ->loadDeferredProps(function (Assert $reload) use (&$result) {
                    $result['pixelforge'] = $reload->toArray()['props']['pixelforge'];
                });
        });

    return $result;
}

function ourRealGeneration(): Generation
{
    $generation = Generation::factory()->create(['created_at' => '2026-11-16 23:22:59']);

    return app(ApplyGenerationSnapshot::class)->handle($generation, Pixelforge::snapshot('generation-succeeded'));
}

test('matches Pixelforge to the nanodollar', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);
    ourRealGeneration();

    $day = reportWithPixelforge($this->admin)['pixelforge']['days']['2026-11-16'];

    expect($day)->toBe([
        'generations' => 1,
        'cost' => '$0.017250000',
        'matches' => true,
        'differences' => [],
    ]);
});

test('shows how a day differs', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);
    ourRealGeneration();

    $day = reportWithPixelforge($this->admin)['pixelforge']['days']['2026-11-17'];

    expect($day['matches'])->toBeFalse()
        ->and($day['differences'])->toContain('Generations: ours 0, Pixelforge 4')
        ->and($day['differences'])->toContain('Image tokens: ours 0, Pixelforge 2,295')
        ->and($day['differences'])->toContain('Cost: ours $0.000000000, Pixelforge $0.182050000')
        ->and($day['differences'][0])->toBe('Generations: ours 0, Pixelforge 4')
        ->and(end($day['differences']))->toBe('Cost: ours $0.000000000, Pixelforge $0.182050000');
});

test('an empty day matches an empty day', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);
    ourRealGeneration();

    expect(reportWithPixelforge($this->admin)['pixelforge']['days']['2026-11-18']['matches'])->toBeTrue();
});

test('counts the matching days', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);
    ourRealGeneration();

    $pixelforge = reportWithPixelforge($this->admin)['pixelforge'];

    expect($pixelforge['available'])->toBeTrue()
        ->and($pixelforge['matching_days'])->toBe(2)
        ->and($pixelforge['day_count'])->toBe(3)
        ->and($pixelforge['total']['matches'])->toBeFalse()
        ->and($pixelforge['total']['generations'])->toBe(5)
        ->and($pixelforge['total']['cost'])->toBe('$0.199300000');
});

test('a day Pixelforge left out compares against zeros', function () {
    $usage = Pixelforge::load('usage');
    unset($usage['data'][2]); // no 18 November
    Http::fake(['pixelforge.test/v1/usage*' => Http::response($usage)]);

    $day = reportWithPixelforge($this->admin)['pixelforge']['days']['2026-11-18'];

    expect($day['matches'])->toBeTrue()
        ->and($day['generations'])->toBe(0);
});

test('asks Pixelforge for exactly the range shown, once', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);

    reportWithPixelforge($this->admin);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-16&end_date=2026-11-18');
});

test("still shows our numbers when Pixelforge can't be reached", function (Closure $answer) {
    Http::fake(['pixelforge.test/v1/usage*' => $answer()]);
    ourRealGeneration();

    $report = reportWithPixelforge($this->admin);

    expect($report['pixelforge'])->toBe([
        'available' => false,
        'message' => "Couldn't reach Pixelforge to compare. Reload to try again.",
    ])->and($report['page']['days'])->toHaveCount(3);
})->with([
    'overloaded (503)' => [fn () => Http::response(Pixelforge::error('server_error', 'overloaded'), 503)],
    'a 200 without the figures' => [fn () => Http::response(['object' => 'usage'])],
]);

test("asks Pixelforge at most 5 times a minute, so the report can't use up the account's rate limit", function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);

    for ($load = 1; $load <= 5; $load++) {
        expect(reportWithPixelforge($this->admin)['pixelforge']['available'])->toBeTrue();
    }
    $another = User::factory()->admin()->create(); // the limit is shared by every admin

    expect(reportWithPixelforge($another)['pixelforge'])->toBe([
        'available' => false,
        'message' => 'Pixelforge comparisons are limited to 5 a minute. Reload in a minute to compare again.',
    ]);
    Http::assertSentCount(5);

    $this->travel(61)->seconds();
    expect(reportWithPixelforge($another)['pixelforge']['available'])->toBeTrue();
});
