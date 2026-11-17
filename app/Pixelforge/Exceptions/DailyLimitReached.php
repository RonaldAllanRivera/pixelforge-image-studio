<?php

namespace App\Pixelforge\Exceptions;

/**
 * This account has used its 500 generations for the UTC day.
 */
final class DailyLimitReached extends Rejected {}
