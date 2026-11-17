<?php

namespace App\Pixelforge\Exceptions;

/**
 * Too many requests this minute (60 per account, across all keys).
 */
final class RateLimited extends RetryLater {}
