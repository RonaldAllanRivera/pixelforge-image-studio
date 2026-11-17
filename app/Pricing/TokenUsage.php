<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * How many tokens of each type a generation used, or might use.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * The same keys Pixelforge uses, so `fromArray()` can read it back.
     *
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
        ];
    }

    /**
     * Build from the `usage` object Pixelforge sends with a succeeded generation.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            inputText: self::count($usage, 'input_text_tokens'),
            inputImage: self::count($usage, 'input_image_tokens'),
            output: self::count($usage, 'output_tokens'),
            reasoning: self::count($usage, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function count(array $usage, string $type): int
    {
        $count = $usage[$type] ?? null;

        if (! is_int($count) || $count < 0) {
            throw new InvalidArgumentException("Pixelforge usage [{$type}] is missing or not a whole number.");
        }

        return $count;
    }
}
