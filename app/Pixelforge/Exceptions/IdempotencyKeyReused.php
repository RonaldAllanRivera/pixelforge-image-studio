<?php

namespace App\Pixelforge\Exceptions;

/**
 * This Idempotency-Key was already used with different parameters.
 */
final class IdempotencyKeyReused extends Rejected {}
