<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// The demo account's login is public in the README, so whoever signs in with it
// must not be able to lock out the next visitor.

beforeEach(function () {
    config(['demo.account_email' => 'demo@example.com']);
});

test('the demo account keeps its name and email', function () {
    $demo = User::factory()->create(['name' => 'Demo Admin', 'email' => 'demo@example.com']);

    $this
        ->actingAs($demo)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Someone else',
            'email' => 'someone@example.com',
        ])
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));

    $demo->refresh();

    expect($demo->name)->toBe('Demo Admin');
    expect($demo->email)->toBe('demo@example.com');
});

test('the demo account keeps its password', function () {
    $demo = User::factory()->create(['email' => 'demo@example.com']);

    $this
        ->actingAs($demo)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('password', $demo->refresh()->password))->toBeTrue();
});

test('the demo account cannot be deleted', function () {
    $demo = User::factory()->create(['email' => 'demo@example.com']);

    $this
        ->actingAs($demo)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($demo);
    expect($demo->fresh())->not->toBeNull();
});
