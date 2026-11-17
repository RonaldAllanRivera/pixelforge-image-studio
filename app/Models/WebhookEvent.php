<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook event Pixelforge delivered, stored in the same transaction that applied it.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property int|null $generation_id
 * @property array<string, mixed> $payload
 * @property int|null $delivery_attempt
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WebhookEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generation_id' => 'integer',
            'delivery_attempt' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
