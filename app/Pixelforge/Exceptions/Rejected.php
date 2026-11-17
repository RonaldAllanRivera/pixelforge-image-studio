<?php

namespace App\Pixelforge\Exceptions;

/**
 * Pixelforge refused the request. Nothing was created and nothing will be billed, so
 * retrying the same request won't help. Also used for error codes we don't know yet.
 */
class Rejected extends PixelforgeException {}
