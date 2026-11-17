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
}
