<?php

namespace App\Credits;

use App\Enums\CreditType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that writes credit entries or changes a balance. Every entry and its balance
 * change happen in the same transaction, the balance with one atomic increment or decrement.
 *
 * A generation holds its maximum charge when it's created. When it ends, the hold is released
 * and, on success, the actual charge taken. The unique (generation_id, type) index means the
 * database itself refuses a second hold, release or charge for one generation.
 */
final readonly class CreditLedger
{
    /**
     * A pretend purchase: no payment is taken.
     */
    public function purchase(User $user, int $amountNano): CreditTransaction
    {
        if ($amountNano <= 0) {
            throw new InvalidArgumentException('A purchase must add a positive amount.');
        }

        return DB::transaction(function () use ($user, $amountNano): CreditTransaction {
            $entry = $this->record($user->id, null, CreditType::Purchase, $amountNano);
            User::query()->whereKey($user->id)->increment('balance_nano', $amountNano);

            return $entry;
        });
    }

    /**
     * Hold the generation's maximum charge. Call inside the transaction that created the
     * generation, with the user's row locked and their balance already checked.
     */
    public function hold(User $lockedUser, Generation $generation): CreditTransaction
    {
        $entry = $this->record($lockedUser->id, $generation->id, CreditType::Hold, -$generation->max_charge_nano);
        User::query()->whereKey($lockedUser->id)->decrement('balance_nano', $generation->max_charge_nano);

        return $entry;
    }

    /**
     * Release a finished generation's hold and, if it succeeded, take its actual charge. Call
     * inside ApplyGenerationSnapshot's transaction, with the generation locked and final.
     * A generation made before balances, or whose account was deleted, has no hold: nothing to do.
     */
    public function settle(Generation $generation): void
    {
        $hold = CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditType::Hold)
            ->first();

        if ($hold === null) {
            return;
        }

        $held = -$hold->amount_nano;
        $this->record($hold->user_id, $generation->id, CreditType::Release, $held);

        $charge = 0;
        if ($generation->status === GenerationStatus::Succeeded) {
            $charge = (int) $generation->charge_nano;
            $this->record($hold->user_id, $generation->id, CreditType::Charge, -$charge);
        }

        // The charge never exceeds the hold (it's capped at the maximum), so this is never negative.
        User::query()->whereKey($hold->user_id)->increment('balance_nano', $held - $charge);
    }

    /**
     * The user's available balance as stored now, not as it was when their model was loaded:
     * a purchase or a settling image may have changed it since.
     */
    public function balance(User $user): int
    {
        return (int) User::query()->whereKey($user->id)->value('balance_nano');
    }

    /**
     * What's held for the user's images still in progress: holds not yet released.
     *
     * @return array{amount_nano: int, images: int}
     */
    public function held(User $user): array
    {
        $open = CreditTransaction::query()->toBase()
            ->where('user_id', $user->id)
            ->where('type', CreditType::Hold->value)
            ->whereNotExists(fn ($release) => $release->selectRaw('1')
                ->from('credit_transactions as releases')
                ->whereColumn('releases.generation_id', 'credit_transactions.generation_id')
                ->where('releases.type', CreditType::Release->value))
            ->selectRaw('count(*) as images, coalesce(sum(amount_nano), 0) as total')
            ->first();

        return ['amount_nano' => -(int) ($open->total ?? 0), 'images' => (int) ($open->images ?? 0)];
    }

    private function record(int $userId, ?int $generationId, CreditType $type, int $amountNano): CreditTransaction
    {
        return CreditTransaction::query()->forceCreate([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nano' => $amountNano,
        ]);
    }
}
