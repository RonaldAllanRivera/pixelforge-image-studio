<?php

use App\Credits\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
});

test('shares the balance with every signed-in page', function () {
    $user = User::factory()->withCredits(10)->create();

    $this->actingAs($user)->get(route('studio'))->assertInertia(fn (Assert $page) => $page
        ->where('credits.balance', '$10.0000')
        ->where('credits.balance_exact', '$10.000000000')
        ->where('credits.held', '$0.0000')
        ->where('credits.held_images', 0)
        ->where('credits.amounts', [5, 10, 25]));
});

test('shows what is held for images in progress', function () {
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->create();
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));

    // The same object the test signed in with: the page reads the balance as stored now.
    $this->actingAs($user)->get(route('studio'))->assertInertia(fn (Assert $page) => $page
        ->where('credits.held', '$0.0863')
        ->where('credits.held_images', 1)
        ->where('credits.balance', '$9.9137'));
});

test('shows what is held rounded up, like the maximum it was quoted as', function () {
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->create(['max_charge_nano' => 21_837_500]); // "never more than $0.0219"
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));

    $this->actingAs($user)->get(route('studio'))->assertInertia(fn (Assert $page) => $page
        ->where('credits.held', '$0.0219')
        ->where('credits.balance', '$9.9781')); // $10 − $0.0219, as the user would work it out
});

test('shares nothing about credits with guests', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('credits', null));
});

test('never sends the raw balance', function () {
    $user = User::factory()->withCredits(10)->create();

    $this->actingAs($user)->get(route('studio'))->assertInertia(fn (Assert $page) => $page
        ->missing('auth.user.balance_nano'));
});
