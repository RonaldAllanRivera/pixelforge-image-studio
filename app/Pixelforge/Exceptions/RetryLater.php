<?php

namespace App\Pixelforge\Exceptions;

/**
 * Pixelforge didn't take the request this time, and nothing was created. Try again
 * after $retryAfter seconds, with the same Idempotency-Key.
 */
abstract class RetryLater extends PixelforgeException
{
    public function __construct(
        string $message,
        ?string $errorCode,
        public readonly int $retryAfter,
    ) {
        parent::__construct($message, $errorCode);
    }
}
