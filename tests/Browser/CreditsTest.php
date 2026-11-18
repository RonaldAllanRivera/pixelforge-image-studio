<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Credits\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

test('buying credits clears a "Not enough credits" error from an earlier try', function () {
    $user = User::factory()->withCredits(1)->create();
    $this->actingAs($user);

    $page = visit(route('studio'))
        ->type('@prompt', 'A red fox')
        ->waitForText('Estimated')
        ->assertEnabled('@generate');

    // Another tab spends the balance after this one showed the price.
    User::query()->whereKey($user->id)->update(['balance_nano' => 0]);

    $page->click('@generate')
        ->waitForText('Not enough credits. This image can cost up to')
        ->click('@buy-credits')
        ->click('@buy-5')
        ->waitForText('Added $5.00. This is a pretend purchase.')
        ->wait(1.5) // the price is asked again for the new balance
        ->assertEnabled('@generate')
        ->assertMissing('@credits-error')
        ->assertNoJavascriptErrors();
});

test('the price is asked again when the balance changes by less than the 4 decimals shown', function () {
    $user = User::factory()->create();
    (new CreditLedger)->purchase($user, 86_274_999); // one nanodollar short of medium's maximum
    Generation::factory()->for($user)->create(); // still running, so the page keeps reloading the balance
    $this->actingAs($user);

    $page = visit(route('studio'))
        ->type('@prompt', 'A red fox')
        ->waitForText('Not enough credits')
        ->assertDisabled('@generate')
        ->assertSeeIn('@balance', '$0.0862');

    User::query()->whereKey($user->id)->increment('balance_nano', 1); // exactly the maximum, still shown as $0.0862

    $page->wait(3.5) // one 2-second reload, then the price
        ->assertSeeIn('@balance', '$0.0862')
        ->assertEnabled('@generate')
        ->assertNoJavascriptErrors();
});

test('money coming in is green on the Credits page, money going out is not', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));
    $generation->forceFill(['status' => GenerationStatus::Failed, 'charge_nano' => 0])->save();
    DB::transaction(fn () => (new CreditLedger)->settle($generation));

    $this->actingAs($user);

    $green = fn (string $type) => "[...document.querySelectorAll('[data-test=amount-{$type}]')].map(e => e.classList.contains('text-emerald-700')).join()";

    visit(route('credits.index'))
        ->assertScript($green('purchase'), 'true')
        ->assertScript($green('release'), 'true')
        ->assertScript($green('hold'), 'false')
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
