<?php

use App\Credits\CreditLedger;
use App\Enums\CreditType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = new CreditLedger;
});

/**
 * Holds the generation's maximum the way CreateGeneration does: inside a transaction, with
 * the user's row locked.
 */
function holdFor(User $user, Generation $generation): void
{
    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));
}

/**
 * @return list<array{0: CreditType, 1: int}>
 */
function entriesOf(User $user): array
{
    return $user->creditTransactions()->orderBy('id')->get()
        ->map(fn (CreditTransaction $entry): array => [$entry->type, $entry->amount_nano])
        ->all();
}

test('a purchase adds the exact amount and records it', function () {
    $user = User::factory()->create();

    $entry = $this->ledger->purchase($user, 10_000_000_000);

    expect($user->fresh()->balance_nano)->toBe(10_000_000_000)
        ->and($entry->type)->toBe(CreditType::Purchase)
        ->and($entry->amount_nano)->toBe(10_000_000_000)
        ->and($entry->generation_id)->toBeNull()
        ->and(entriesOf($user))->toBe([[CreditType::Purchase, 10_000_000_000]]);
});

test('refuses a purchase of zero or less', function (int $amount) {
    $user = User::factory()->create();

    expect(fn () => $this->ledger->purchase($user, $amount))->toThrow(InvalidArgumentException::class);

    expect($user->fresh()->balance_nano)->toBe(0)
        ->and(CreditTransaction::count())->toBe(0);
})->with([0, -1]);

test('a hold takes the maximum charge from the balance', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();

    holdFor($user, $generation);

    expect($user->fresh()->balance_nano)->toBe(913_725_000)
        ->and(entriesOf($user))->toBe([
            [CreditType::Purchase, 1_000_000_000],
            [CreditType::Hold, -86_275_000],
        ])
        ->and($generation->creditTransactions()->sole()->type)->toBe(CreditType::Hold);
});

test('settling a success releases the hold and takes the actual charge', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();
    holdFor($user, $generation);

    $generation->forceFill(['status' => GenerationStatus::Succeeded, 'charge_nano' => 21_562_500])->save();
    DB::transaction(fn () => $this->ledger->settle($generation));

    expect($generation->creditTransactions()->orderBy('id')->get()->map(fn ($e) => [$e->type, $e->amount_nano])->all())->toBe([
        [CreditType::Hold, -86_275_000],
        [CreditType::Release, 86_275_000],
        [CreditType::Charge, -21_562_500],
    ])->and($user->fresh()->balance_nano)->toBe(978_437_500);
});

test('settling a failure only releases the hold', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();
    holdFor($user, $generation);

    $generation->forceFill(['status' => GenerationStatus::Failed, 'charge_nano' => 0])->save();
    DB::transaction(fn () => $this->ledger->settle($generation));

    expect($generation->creditTransactions()->orderBy('id')->pluck('type')->all())->toBe([CreditType::Hold, CreditType::Release])
        ->and($user->fresh()->balance_nano)->toBe(1_000_000_000);
});

test('nothing is held once the image has settled', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();
    holdFor($user, $generation);

    expect($this->ledger->held($user))->toBe(['amount_nano' => 86_275_000, 'images' => 1]);

    $generation->forceFill(['status' => GenerationStatus::Succeeded, 'charge_nano' => 21_562_500])->save();
    DB::transaction(fn () => $this->ledger->settle($generation));

    expect($this->ledger->held($user))->toBe(['amount_nano' => 0, 'images' => 0]);
});

test('settling a generation without a hold writes nothing', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();

    $generation->forceFill(['status' => GenerationStatus::Succeeded, 'charge_nano' => 21_562_500])->save();
    DB::transaction(fn () => $this->ledger->settle($generation));

    expect($generation->creditTransactions()->count())->toBe(0)
        ->and($user->fresh()->balance_nano)->toBe(1_000_000_000);
});

test('the database refuses a second charge for one generation', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();
    $charge = ['user_id' => $user->id, 'generation_id' => $generation->id, 'type' => CreditType::Charge, 'amount_nano' => -1];

    CreditTransaction::query()->forceCreate($charge);

    expect(fn () => CreditTransaction::query()->forceCreate($charge))->toThrow(UniqueConstraintViolationException::class);
});

test('the database refuses a negative balance', function () {
    $user = User::factory()->create();

    expect(fn () => User::query()->whereKey($user->id)->update(['balance_nano' => -1]))->toThrow(QueryException::class);
});

test('the balance never reaches the browser as a number', function () {
    $user = User::factory()->withCredits(10)->create();

    expect($user->fresh()->toArray())->not->toHaveKey('balance_nano');
});

test('deleting an account deletes its wallet but keeps its generations', function () {
    $user = User::factory()->withCredits(1)->create();
    $generation = Generation::factory()->for($user)->create();
    holdFor($user, $generation);

    $user->delete();

    expect(CreditTransaction::count())->toBe(0)
        ->and($generation->fresh())->not->toBeNull()
        ->and($generation->fresh()->user_id)->toBeNull();
});
