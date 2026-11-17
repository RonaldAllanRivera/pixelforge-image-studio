<?php

use App\Pricing\TokenUsage;

test('reads the usage object Pixelforge sends with a succeeded generation', function () {
    $usage = TokenUsage::fromArray([
        'input_text_tokens' => 10,
        'input_image_tokens' => 0,
        'output_tokens' => 280,
        'reasoning_tokens' => 480,
        'total_tokens' => 770,
    ]);

    expect($usage)->toEqual(new TokenUsage(inputText: 10, inputImage: 0, output: 280, reasoning: 480));
});

test('refuses usage with a token count missing', function () {
    TokenUsage::fromArray(['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280]);
})->throws(InvalidArgumentException::class, 'reasoning_tokens');

test('refuses a negative token count', function () {
    TokenUsage::fromArray(['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 480]);
})->throws(InvalidArgumentException::class, 'input_text_tokens');
