<?php

use App\Models\User;

test('makes an existing account an admin', function () {
    $user = User::factory()->create(['email' => 'grader@example.com']);

    $this->artisan('users:make-admin', ['email' => 'grader@example.com'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('takes admin away with --remove', function () {
    $admin = User::factory()->admin()->create(['email' => 'grader@example.com']);

    $this->artisan('users:make-admin', ['email' => 'grader@example.com', '--remove' => true])->assertSuccessful();

    expect($admin->fresh()->is_admin)->toBeFalse();
});

test('fails for an unknown email', function () {
    $user = User::factory()->create(['email' => 'someone@example.com']);

    $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertExitCode(1);

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and(User::query()->where('is_admin', true)->count())->toBe(0);
});
