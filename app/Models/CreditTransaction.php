<?php

namespace App\Models;

use App\Enums\CreditType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a user's balance. Entries are only ever added, never edited or deleted by the
 * app, and only CreditLedger adds them. A user's balance always equals the sum of their entries.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id Null for purchases.
 * @property CreditType $type
 * @property int $amount_nano Signed: purchase +, hold -, release +, charge -.
 * @property CarbonImmutable|null $created_at
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditType::class,
            'amount_nano' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
