<?php

namespace Tests\Fixtures;

/**
 * Real Pixelforge responses, saved under tests/Fixtures/pixelforge.
 */
final class Pixelforge
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/pixelforge/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * An error body in Pixelforge's documented shape, for errors we can't trigger on demand.
     *
     * @return array{error: array{type: string, code: string, message: string}}
     */
    public static function error(string $type, string $code): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => "Pixelforge says {$code}."]];
    }
}
