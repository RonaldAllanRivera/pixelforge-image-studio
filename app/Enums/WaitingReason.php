<?php

namespace App\Enums;

/**
 * Why a pending generation hasn't been accepted by Pixelforge yet. Shown to the user.
 */
enum WaitingReason: string
{
    /** Pixelforge runs at most 3 generations at once per account. */
    case FreeSlot = 'free_slot';

    /** Too many requests to Pixelforge this minute. */
    case RateLimit = 'rate_limit';

    /** Pixelforge said it was overloaded; nothing was created. */
    case Overloaded = 'overloaded';

    /** Pixelforge didn't answer clearly; we're checking with the same Idempotency-Key. */
    case UnknownOutcome = 'unknown_outcome';

    /** What the user sees on the generation's status badge. */
    public function label(): string
    {
        return match ($this) {
            self::FreeSlot => 'Waiting for a free slot',
            self::RateLimit => 'Waiting: Pixelforge rate limit',
            self::Overloaded => 'Retrying: Pixelforge is busy',
            self::UnknownOutcome => 'Checking with Pixelforge',
        };
    }
}
