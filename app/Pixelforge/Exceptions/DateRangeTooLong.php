<?php

namespace App\Pixelforge\Exceptions;

/**
 * GET /v1/usage was asked for more days than Pixelforge allows (92).
 */
final class DateRangeTooLong extends Rejected {}
