<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Credits\CreditLedger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Pixelforge;

test('shows the owner their generation with its cost math', function () {
    $generation = Generation::factory()->create();

    $this->actingAs($generation->user)->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Show')
            ->where('generation.id', $generation->id)
            ->has('generation.tokens', 4)
        );
});

test('lists the credits behind the generation', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));
    app(ApplyGenerationSnapshot::class)->handle($generation, Pixelforge::snapshot('generation-succeeded'));

    $this->actingAs($user)->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.credits', [
                ['label' => 'Held', 'amount' => '−$0.0863', 'amount_exact' => '−$0.086275000'],
                ['label' => 'Released', 'amount' => '+$0.0863', 'amount_exact' => '+$0.086275000'],
                ['label' => 'Charged', 'amount' => '−$0.0216', 'amount_exact' => '−$0.021562500'],
            ])
        );
});

test('a generation made before balances lists no credits', function () {
    $generation = Generation::factory()->create();

    $this->actingAs($generation->user)->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page->where('generation.credits', []));
});

test("another user can't see it", function () {
    $generation = Generation::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('generations.show', $generation))->assertForbidden();
});

test("nobody can see a deleted account's generation", function () {
    $generation = Generation::factory()->create(['user_id' => null]);

    $this->actingAs(User::factory()->create())->get(route('generations.show', $generation))->assertForbidden();
});

test('a link that is not a generation number is a 404, not a server error', function () {
    $this->actingAs(User::factory()->create())->get('/generations/abc')->assertNotFound();
});

test('guests are sent to the login page', function () {
    $this->get(route('generations.show', Generation::factory()->create()))->assertRedirect(route('login'));
});
