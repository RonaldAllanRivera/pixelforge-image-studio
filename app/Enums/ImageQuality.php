<?php

namespace App\Enums;

/**
 * The quality levels Pixelforge offers. Higher quality means more output and reasoning tokens.
 */
enum ImageQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
