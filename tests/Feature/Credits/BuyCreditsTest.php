<?php

use App\Enums\CreditType;
use App\Models\CreditTransaction;
use App\Models\User;

test('buys exactly the amount chosen', function (int $dollars) {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('studio'))->post(route('credits.purchases.store'), ['amount' => $dollars])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('studio'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Added \${$dollars}.00. This is a pretend purchase."]);

    expect($user->fresh()->balance_nano)->toBe($dollars * 1_000_000_000)
        ->and($user->creditTransactions()->sole())
        ->type->toBe(CreditType::Purchase)
        ->amount_nano->toBe($dollars * 1_000_000_000);
})->with([5, 10, 25]);

test('refuses any other amount', function (array $form) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.purchases.store'), $form)
        ->assertSessionHasErrors('amount');

    expect($user->fresh()->balance_nano)->toBe(0)
        ->and(CreditTransaction::count())->toBe(0);
})->with([
    'seven dollars' => [['amount' => 7]],
    'zero' => [['amount' => 0]],
    'a negative amount' => [['amount' => -5]],
    'text' => [['amount' => 'abc']],
    'nothing' => [[]],
]);

test('guests are sent to log in', function () {
    $this->post(route('credits.purchases.store'), ['amount' => 5])->assertRedirect(route('login'));

    expect(CreditTransaction::count())->toBe(0);
});

test('limits purchases to 10 a minute', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)->post(route('credits.purchases.store'), ['amount' => 5])->assertRedirect();
    }

    $this->actingAs($user)->post(route('credits.purchases.store'), ['amount' => 5])->assertTooManyRequests();
    expect($user->fresh()->balance_nano)->toBe(50_000_000_000);
});
