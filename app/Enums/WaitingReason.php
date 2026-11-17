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
}
