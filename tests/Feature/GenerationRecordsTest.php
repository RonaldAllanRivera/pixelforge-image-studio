<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

test('deleting an account keeps its generations, because they are billing records', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'provider_id' => 'gen_billed',
    ]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect();

    expect(User::find($user->id))->toBeNull()
        ->and($generation->fresh())->not->toBeNull()
        ->and($generation->fresh()->user_id)->toBeNull();
});
