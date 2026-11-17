<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\WaitingReason;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image generation. Money is in nanodollars: "cost" is what Pixelforge charges us,
 * "charge" is what the user pays. Status and money only change in ApplyGenerationSnapshot.
 *
 * @property int $id
 * @property int|null $user_id Null once the user deleted their account; the record stays for billing.
 * @property string|null $provider_id
 * @property string $idempotency_key
 * @property GenerationStatus $status
 * @property WaitingReason|null $waiting_reason
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property array<string, string>|null $rates
 * @property int $estimated_cost_nano
 * @property int $estimated_charge_nano
 * @property int $max_cost_nano
 * @property int $max_charge_nano
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nano
 * @property int|null $charge_nano
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property int $overloaded_attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'idempotency_key', 'status', 'prompt', 'size', 'quality', 'reference_images',
    'estimated_cost_nano', 'estimated_charge_nano', 'max_cost_nano', 'max_charge_nano',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'waiting_reason' => WaitingReason::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_cost_nano' => 'integer',
            'estimated_charge_nano' => 'integer',
            'max_cost_nano' => 'integer',
            'max_charge_nano' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nano' => 'integer',
            'charge_nano' => 'integer',
            'submit_attempts' => 'integer',
            'overloaded_attempts' => 'integer',
            'image_url_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
