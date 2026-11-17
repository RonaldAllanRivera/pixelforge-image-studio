<?php

namespace App\Enums;

/**
 * Where a generation is in its life: pending → queued → processing → succeeded or failed.
 *
 * "Pending" is ours alone: saved locally, not yet accepted by Pixelforge. The other four
 * are Pixelforge's statuses.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Succeeded and failed never change again.
     */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Whether news of $next moves a generation forward. Duplicate, late and out-of-order
     * news doesn't, so it changes nothing: that's how a status can only ever move forward.
     */
    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
