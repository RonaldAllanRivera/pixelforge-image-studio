<?php

namespace App\Enums;

/**
 * Where a generation is in its life: pending → queued → processing → succeeded or failed.
 *
 * "Pending" is ours alone: saved locally, not yet accepted by Pixelforge. The other four
 * are Pixelforge's statuses.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
