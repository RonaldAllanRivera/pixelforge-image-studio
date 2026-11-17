<?php

use App\Models\User;

test('new users are not admins', function () {
    $user = User::factory()->create();

    expect($user->refresh()->is_admin)->toBeFalse();
});

test('the admin factory state makes an admin', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->refresh()->is_admin)->toBeTrue();
});

test('signing up cannot make someone an admin', function () {
    $this->post(route('register.store'), [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'is_admin' => true,
    ]);

    expect(User::where('email', 'mallory@example.com')->sole()->is_admin)->toBeFalse();
});

test('the local seeder creates a normal user and an admin', function () {
    $this->seed();

    expect(User::where('email', 'test@example.com')->sole()->is_admin)->toBeFalse()
        ->and(User::where('email', 'admin@example.com')->sole()->is_admin)->toBeTrue();
});
