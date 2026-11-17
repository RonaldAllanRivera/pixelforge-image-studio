<?php

namespace App\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Something went wrong talking to Pixelforge. The subclass says what to do next:
 * RetryLater, Rejected (nothing was created) or OutcomeUnknown.
 */
abstract class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
