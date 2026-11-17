<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Credits\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test("lists the user's own entries, newest first", function () {
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->create(); // "A red fox", at most $0.086275
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));
    User::factory()->withCredits(25)->create(); // someone else's purchase

    $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
        ->component('credits/Index')
        ->has('entries.data', 2)
        ->where('entries.data.0.type', 'hold')
        ->where('entries.data.0.label', 'Held')
        ->where('entries.data.0.amount', '−$0.0863')
        ->where('entries.data.0.amount_exact', '−$0.086275000')
        ->where('entries.data.0.generation', ['id' => $generation->id, 'prompt' => 'A red fox'])
        ->where('entries.data.1.label', 'Purchase')
        ->where('entries.data.1.amount', '+$10.0000')
        ->where('entries.data.1.generation', null)
        ->has('entries.data.0.created_at'));
});

test('shows a hold and its release as the maximum was quoted, rounded up', function () {
    $user = User::factory()->withCredits(1)->create();
    // Low "A red fox": at most $0.0218375, quoted as "never more than $0.0219".
    $generation = Generation::factory()->for($user)->create(['max_charge_nano' => 21_837_500]);
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));
    app(ApplyGenerationSnapshot::class)->failBeforeAccepted($generation, 'content_policy_violation', 'Rejected.');

    $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.label', 'Released')
        ->where('entries.data.0.amount', '+$0.0219')
        ->where('entries.data.1.label', 'Held')
        ->where('entries.data.1.amount', '−$0.0219')
        ->where('entries.data.1.amount_exact', '−$0.021837500'));
});

test('pages the entries 20 at a time', function () {
    $user = User::factory()->create();
    $ledger = new CreditLedger;
    for ($i = 0; $i < 21; $i++) {
        $ledger->purchase($user, 5_000_000_000);
    }

    $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 20)
        ->where('entries.meta.total', 21));

    $this->actingAs($user)->get(route('credits.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 1));
});

test('guests are sent to log in', function () {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
});
