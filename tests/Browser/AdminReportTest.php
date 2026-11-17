<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Pixelforge;

/*
| The same data as the feature tests: Pixelforge's real usage for 16–18 November, and our
| one real generation, which Pixelforge counts on 16 November.
*/
beforeEach(function () {
    $this->travelTo(now()->parse('2026-11-18 12:00:00'));
    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage')),
        'pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing')),
    ]);

    $allan = User::factory()->create(['name' => 'Allan Rivera', 'email' => 'allan@gmail.com']);
    $generation = Generation::factory()->for($allan)->create(['created_at' => '2026-11-16 23:22:59']);
    app(ApplyGenerationSnapshot::class)->handle($generation, Pixelforge::snapshot('generation-succeeded'));

    $this->range = ['start' => '2026-11-16', 'end' => '2026-11-18'];
});

test('an admin sees each day checked against Pixelforge', function () {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.report', $this->range))
        ->waitForText('2 of 3 days match Pixelforge')
        ->assertSeeIn('@matches-summary', '2 of 3 days match Pixelforge')
        ->assertSee('$0.017250000')
        ->assertSee('Matches')
        ->assertSee('Generations: ours 0, Pixelforge 4')
        ->assertSee('Pixelforge counts a generation on the UTC day it finished.')
        ->assertNoJavascriptErrors();
});

test('clicking a day shows who spent what that day', function () {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.report', $this->range))
        ->waitForText('2 of 3 days match Pixelforge')
        ->click('@day-2026-11-16')
        ->waitForText('1 of 1 days match Pixelforge')
        ->assertQueryStringHas('start', '2026-11-16')
        ->assertQueryStringHas('end', '2026-11-16')
        ->assertSee('Allan Rivera')
        ->assertSee('al•••@gmail.com')
        ->assertDontSee('allan@gmail.com')
        ->assertNoJavascriptErrors();
});

test("a normal user can't see the report", function () {
    $this->actingAs(User::factory()->create());

    visit(route('studio'))
        ->assertSee('Studio')
        ->assertDontSee('Admin report')
        ->assertNoJavascriptErrors();

    visit(route('admin.report'))->assertSee('403');
});

test('the report fits a phone screen', function () {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.report', $this->range))->on()->mobile()
        ->waitForText('2 of 3 days match Pixelforge')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();
});
