<?php

namespace App\Pixelforge\Exceptions;

/**
 * Pixelforge found a problem with the request's parameters. Our own form validation
 * should prevent this, so it points to a bug on our side.
 */
final class ValidationFailed extends Rejected
{
    /**
     * @param  array<string, list<string>>  $errors  Every problem, by parameter.
     */
    public function __construct(
        string $message,
        public readonly array $errors,
    ) {
        parent::__construct($message, 'validation_failed');
    }
}
