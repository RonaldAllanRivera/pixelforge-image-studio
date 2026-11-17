<?php

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shows the owner their generation with its cost math', function () {
    $generation = Generation::factory()->create();

    $this->actingAs($generation->user)->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Show')
            ->where('generation.id', $generation->id)
            ->has('generation.tokens', 4)
        );
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
