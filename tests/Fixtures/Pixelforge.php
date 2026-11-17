<?php

namespace Tests\Fixtures;

use App\Pixelforge\GenerationSnapshot;

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
     * A generation snapshot read from a fixture, with some fields changed.
     *
     * @param  array<string, mixed>  $changes  Merged recursively, so ['usage' => ['reasoning_tokens' => 1]] changes one count.
     */
    public static function snapshot(string $name, array $changes = []): GenerationSnapshot
    {
        return GenerationSnapshot::fromArray(array_replace_recursive(self::load($name), $changes));
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
