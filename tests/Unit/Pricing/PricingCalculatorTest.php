<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pricing\PriceSheet;
use App\Pricing\PricingCalculator;
use App\Pricing\TokenUsage;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    $this->calculator = new PricingCalculator(markupPercent: 25);
    $this->sheet = PriceSheet::fromPricingResponse(Pixelforge::load('pricing'));
});

test('costs token usage exactly, matching the docs usage example', function () {
    // GET /v1/usage example in the docs: one day, two generations, "cost_usd": "0.169080000".
    $usage = new TokenUsage(inputText: 41, inputImage: 0, output: 3_300, reasoning: 2_950);

    expect($this->calculator->cost($usage, $this->sheet->rates))->toBe(169_080_000);
});

test('costs and charges the live test generation exactly', function () {
    // Made with the local key on 2026-11-17: 10 text, 280 output and 480 reasoning tokens.
    $cost = $this->calculator->cost(new TokenUsage(10, 0, 280, 480), $this->sheet->rates);

    expect($cost)->toBe(17_250_000)
        ->and($this->calculator->charge($cost))->toBe(21_562_500);
});

test('quotes the docs worst case exactly', function () {
    // Docs: high, 1536x1024, a 400-character prompt and 2 reference images costs at most $0.373875.
    $quote = $this->calculator->quote(str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2, $this->sheet);

    expect($quote->maximumUsage)->toEqual(new TokenUsage(inputText: 115, inputImage: 1_530, output: 6_450, reasoning: 8_000))
        ->and($quote->maximumCost)->toBe(373_875_000);
});

test('quotes a typical prompt with its estimate, maximum and charges', function () {
    $quote = $this->calculator->quote('A red fox', ImageSize::Square, ImageQuality::Medium, 0, $this->sheet);

    // 9 characters = 3 text tokens; 1,100 output tokens; reasoning estimated at 1,100 (middle of 200 to 2,000).
    expect($quote->estimatedUsage)->toEqual(new TokenUsage(3, 0, 1_100, 1_100))
        ->and($quote->estimatedCost)->toBe(57_765_000)
        ->and($quote->estimatedCharge)->toBe(72_206_250)
        // At most 4 text tokens (3 + 15%, rounded up) and 2,000 reasoning tokens.
        ->and($quote->maximumUsage)->toEqual(new TokenUsage(4, 0, 1_100, 2_000))
        ->and($quote->maximumCost)->toBe(69_020_000)
        ->and($quote->maximumCharge)->toBe(86_275_000);
});

test('counts prompt characters as Unicode characters, not bytes', function () {
    $prompt = 'café 🎨🎨'; // 7 characters, but 14 bytes

    $quote = $this->calculator->quote($prompt, ImageSize::Square, ImageQuality::Low, 0, $this->sheet);

    expect($quote->estimatedUsage->inputText)->toBe(2); // ceil(7 / 4), where bytes would give 4
});

test('bills 765 input image tokens per reference image', function (int $references) {
    $quote = $this->calculator->quote('A red fox', ImageSize::Square, ImageQuality::Low, $references, $this->sheet);

    expect($quote->estimatedUsage->inputImage)->toBe($references * 765)
        ->and($quote->maximumUsage->inputImage)->toBe($references * 765);
})->with([0, 1, 2, 3, 4]);

test('estimates reasoning at the middle of its range and caps it at the top', function (ImageQuality $quality, int $estimated, int $maximum) {
    $quote = $this->calculator->quote('A red fox', ImageSize::Square, $quality, 0, $this->sheet);

    expect($quote->estimatedUsage->reasoning)->toBe($estimated)
        ->and($quote->maximumUsage->reasoning)->toBe($maximum);
})->with([
    'low' => [ImageQuality::Low, 250, 500],
    'medium' => [ImageQuality::Medium, 1_100, 2_000],
    'high' => [ImageQuality::High, 4_500, 8_000],
]);

test('always allows for the full +15% text token variance in the maximum', function () {
    foreach (range(1, 4_000) as $characters) {
        $quote = $this->calculator->quote(str_repeat('a', $characters), ImageSize::Square, ImageQuality::Low, 0, $this->sheet);
        $estimated = $quote->estimatedUsage->inputText;

        // Pixelforge's actual count is at most 15% above the estimate.
        expect($quote->maximumUsage->inputText * 100)->toBeGreaterThanOrEqual($estimated * 115);
    }
});

test('refuses a negative number of reference images', function () {
    $this->calculator->quote('A red fox', ImageSize::Square, ImageQuality::Low, -1, $this->sheet);
})->throws(InvalidArgumentException::class);
