<?php

namespace App\Pixelforge\Exceptions;

/**
 * We can't tell what happened: no answer, a server error, or an answer we can't read.
 * The generation may or may not exist, so it must never be marked as failed. Retrying
 * with the same Idempotency-Key is safe: it returns the generation if it was created.
 */
final class OutcomeUnknown extends PixelforgeException {}
