<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
    Queue::fake();
});

test("a new account at \$0 can't generate until it buys credits", function () {
    $this->actingAs(User::factory()->create());

    visit(route('studio'))
        ->type('@prompt', 'A red fox')
        ->waitForText('Estimated')
        ->assertSee('Not enough credits')
        ->assertDisabled('@generate')
        ->assertSeeIn('@balance', '$0.0000')
        ->assertNoJavascriptErrors();
});

test('buying credits in the dialog keeps the prompt and turns Generate on', function () {
    $this->actingAs(User::factory()->create());

    visit(route('studio'))
        ->type('@prompt', 'A red fox')
        ->waitForText('Not enough credits')
        ->click('@buy-credits')
        ->click('@buy-5')
        ->waitForText('Added $5.00. This is a pretend purchase.')
        ->assertValue('@prompt', 'A red fox')
        ->waitForText('Generate')
        ->assertEnabled('@generate')
        ->assertSeeIn('@credits-link', '$5.0000')
        ->assertNoJavascriptErrors();
});

test('the balance drops by the actual charge when the image is done, and the Credits page explains it', function () {
    $this->actingAs(User::factory()->withCredits(1)->create());

    $page = visit(route('studio'))
        ->type('@prompt', 'A red fox [succeed]')
        ->waitForText('Estimated')
        ->click('@generate')
        ->waitForText('A red fox [succeed]')
        ->assertSeeIn('@balance', '$0.9137'); // $1 less the $0.086275 held

    // Pixelforge reports the success, the way a webhook would.
    app(ApplyGenerationSnapshot::class)->handle(Generation::sole(), Pixelforge::snapshot('generation-succeeded'));

    $page->waitForText('$0.9784') // $1 − $0.0215625, rounded down
        ->assertSeeIn('@balance', '$0.9784')
        ->assertNoJavascriptErrors();

    visit(route('credits.index'))
        ->assertSee('Held')
        ->assertSee('Released')
        ->assertSee('Charged')
        ->assertSee('A red fox [succeed]')
        ->assertNoJavascriptErrors();
});

test('the credits screens fit a phone screen', function () {
    $this->actingAs(User::factory()->create());

    visit(route('studio'))->on()->mobile()
        ->type('@prompt', 'A red fox')
        ->waitForText('Not enough credits')
        ->assertVisible('@buy-credits')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();

    visit(route('credits.index'))->on()->mobile()
        ->assertVisible('@buy-credits')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();
});
