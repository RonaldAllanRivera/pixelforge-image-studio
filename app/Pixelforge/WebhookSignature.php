<?php

namespace App\Pixelforge;

use Illuminate\Container\Attributes\Config;

/**
 * Checks the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256>", where the
 * HMAC is of "<t>.<raw body>" with this environment's webhook signing secret.
 */
final readonly class WebhookSignature
{
    /** Older (or newer) timestamps are refused, so a captured request can't be replayed later. */
    public const TOLERANCE_SECONDS = 300;

    public function __construct(
        #[Config('pixelforge.webhook_secret')]
        private ?string $secret,
    ) {}

    /**
     * Must be given the raw request body, exactly as received, before any JSON decoding.
     */
    public function isValid(string $header, string $body, ?int $now = null): bool
    {
        if ($this->secret === null || $this->secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? now()->getTimestamp()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        // Any match counts: during a secret rotation the header can carry more than one.
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
