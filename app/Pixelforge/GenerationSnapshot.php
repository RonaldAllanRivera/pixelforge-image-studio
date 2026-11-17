<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What Pixelforge says about a generation at one moment. The create response, a fetch
 * and every webhook event carry the same generation object, so all three become this.
 */
final readonly class GenerationSnapshot
{
    /**
     * @param  array<string, string>  $metadata  Echoed back exactly as we sent it.
     * @param  array<string, string>|null  $rates  USD per 1M tokens, fixed when Pixelforge accepted it.
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $metadata,
        public ?array $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $object  A Pixelforge generation object.
     */
    public static function fromArray(array $object): self
    {
        $id = $object['id'] ?? null;
        $status = is_string($object['status'] ?? null) ? GenerationStatus::tryFrom($object['status']) : null;

        if (! is_string($id) || $id === '' || $status === null || $status === GenerationStatus::Pending) {
            throw new InvalidArgumentException('This is not a Pixelforge generation object.');
        }

        $usage = $object['usage'] ?? null;
        $error = $object['error'] ?? null;

        return new self(
            id: $id,
            status: $status,
            metadata: self::strings($object['metadata'] ?? []),
            rates: is_array($object['rates'] ?? null) ? self::strings($object['rates']) : null,
            usage: is_array($usage) ? TokenUsage::fromArray($usage) : null,
            imageUrl: self::stringOrNull($object['image_url'] ?? null),
            imageUrlExpiresAt: self::timeOrNull($object['image_url_expires_at'] ?? null),
            errorCode: is_array($error) ? self::stringOrNull($error['code'] ?? null) : null,
            errorMessage: is_array($error) ? self::stringOrNull($error['message'] ?? null) : null,
            startedAt: self::timeOrNull($object['started_at'] ?? null),
            completedAt: self::timeOrNull($object['completed_at'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timeOrNull(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
