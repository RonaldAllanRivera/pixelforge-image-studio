<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Credits\CreditLedger;
use App\Enums\CreditType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Pixelforge;

/**
 * Holds a new generation's maximum the way CreateGeneration does, and returns the generation.
 */
function heldGeneration(User $user): Generation
{
    $generation = Generation::factory()->for($user)->create(); // at most $0.086275

    DB::transaction(fn () => (new CreditLedger)->hold(User::query()->lockForUpdate()->findOrFail($user->id), $generation));

    return $generation;
}

/**
 * @return list<CreditType>
 */
function entryTypes(Generation $generation): array
{
    return $generation->creditTransactions()->orderBy('id')->pluck('type')->all();
}

beforeEach(function () {
    $this->apply = app(ApplyGenerationSnapshot::class);
    $this->user = User::factory()->withCredits(1)->create();
    $this->generation = heldGeneration($this->user);
});

test('a success releases the hold and charges the actual cost', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));

    expect(entryTypes($this->generation))->toBe([CreditType::Hold, CreditType::Release, CreditType::Charge])
        ->and($this->generation->creditTransactions()->where('type', CreditType::Charge)->sole()->amount_nano)->toBe(-21_562_500)
        ->and($this->user->fresh()->balance_nano)->toBe(978_437_500);
});

test('a success reported twice charges once', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));

    expect($this->generation->creditTransactions()->where('type', CreditType::Charge)->count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(978_437_500);
});

test('a poll and a webhook reporting success charge once', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['status' => 'processing', 'usage' => null, 'completed_at' => null]));
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded')); // the webhook
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded')); // a late poll

    expect($this->generation->creditTransactions()->where('type', CreditType::Charge)->count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(978_437_500);
});

test('a failure from Pixelforge releases the hold and charges nothing', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-failed'));

    expect(entryTypes($this->generation))->toBe([CreditType::Hold, CreditType::Release])
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000);
});

test('a generation refused before Pixelforge accepted it releases the hold', function () {
    $this->apply->failBeforeAccepted($this->generation, 'content_policy_violation', 'Your prompt was rejected by our content policy.');

    expect(entryTypes($this->generation))->toBe([CreditType::Hold, CreditType::Release])
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000);
});

test('a capped charge settles at the maximum', function () {
    Log::spy();

    // 5,000 output tokens: a charge of about $0.26, over the $0.086275 maximum quoted.
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['usage' => ['output_tokens' => 5000]]));

    expect($this->generation->creditTransactions()->where('type', CreditType::Charge)->sole()->amount_nano)->toBe(-86_275_000)
        ->and($this->user->fresh()->balance_nano)->toBe(913_725_000);
});

test('a purchase between hold and settle is kept', function () {
    (new CreditLedger)->purchase($this->user, 5_000_000_000);

    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));

    expect($this->user->fresh()->balance_nano)->toBe(5_978_437_500);
});

test('a generation made before balances settles without touching credits', function () {
    $before = Generation::factory()->for($this->user)->create(); // no hold

    $this->apply->handle($before, Pixelforge::snapshot('generation-succeeded'));

    expect($before->creditTransactions()->count())->toBe(0)
        ->and($before->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->user->fresh()->balance_nano)->toBe(913_725_000); // only this test's other hold
});

test('a generation whose account was deleted still settles', function () {
    $this->user->delete();

    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(CreditTransaction::count())->toBe(0);
});

test('every balance equals the sum of its entries after a mixed run', function () {
    $ledger = new CreditLedger;
    $users = [$this->user, User::factory()->withCredits(2)->create()];

    foreach ($users as $user) {
        $succeeded = heldGeneration($user);
        $failed = heldGeneration($user);
        $refused = heldGeneration($user);
        $running = heldGeneration($user);

        $success = Pixelforge::snapshot('generation-succeeded', ['id' => "gen_ok_{$user->id}"]);

        $this->apply->handle($succeeded, $success);
        $ledger->purchase($user, 3_000_000_000);
        $this->apply->handle($succeeded, $success); // duplicate
        $this->apply->handle($failed, Pixelforge::snapshot('generation-failed', ['id' => "gen_failed_{$user->id}"]));
        $this->apply->failBeforeAccepted($refused, 'daily_limit_reached', 'Daily limit reached.');
        $this->apply->handle($running, Pixelforge::snapshot('generation-succeeded', ['id' => "gen_running_{$user->id}", 'status' => 'processing', 'usage' => null, 'completed_at' => null]));
    }

    foreach ($users as $user) {
        expect($user->fresh()->balance_nano)->toBe((int) $user->creditTransactions()->sum('amount_nano'));
    }
    // The first user's numbers by hand: $1 − first hold still held (beforeEach's) − one charge − one running hold + $3.
    expect($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 86_275_000 - 21_562_500 - 86_275_000 + 3_000_000_000);
});
