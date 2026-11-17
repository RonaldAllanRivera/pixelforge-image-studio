<?php

namespace App\Pixelforge\Exceptions;

/**
 * Already 3 generations queued or processing on this account.
 */
final class ConcurrencyLimited extends RetryLater {}
