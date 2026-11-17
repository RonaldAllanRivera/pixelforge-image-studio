<?php

use App\Pricing\TokenRates;
use Tests\Fixtures\Pixelforge;

test('turns per-million-token rates into whole nanodollars per token', function () {
    $rates = TokenRates::fromArray(Pixelforge::load('pricing')['rates']);

    expect($rates->inputText)->toBe(5_000)       // $5.00 per 1M tokens
        ->and($rates->inputImage)->toBe(10_000)  // $10.00
        ->and($rates->output)->toBe(40_000)      // $40.00
        ->and($rates->reasoning)->toBe(12_500);  // $12.50
});

test('accepts a rate down to a tenth of a cent per million tokens', function () {
    $rates = TokenRates::fromArray([...Pixelforge::load('pricing')['rates'], 'input_text_tokens' => '0.001']);

    expect($rates->inputText)->toBe(1);
});

test('refuses a rate that would make a token cost a fraction of a nanodollar', function () {
    TokenRates::fromArray([...Pixelforge::load('pricing')['rates'], 'output_tokens' => '40.0001']);
})->throws(InvalidArgumentException::class, 'output_tokens');

test('refuses a negative rate', function () {
    TokenRates::fromArray([...Pixelforge::load('pricing')['rates'], 'reasoning_tokens' => '-1.00']);
})->throws(InvalidArgumentException::class, 'reasoning_tokens');

test('refuses rates with a token type missing', function () {
    $rates = Pixelforge::load('pricing')['rates'];
    unset($rates['input_image_tokens']);

    TokenRates::fromArray($rates);
})->throws(InvalidArgumentException::class, 'input_image_tokens');
