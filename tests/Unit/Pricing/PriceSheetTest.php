<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pricing\PriceSheet;
use Tests\Fixtures\Pixelforge;

test('reads the token rules from the real pricing response', function () {
    $sheet = PriceSheet::fromPricingResponse(Pixelforge::load('pricing'));

    expect($sheet->rates->output)->toBe(40_000)
        ->and($sheet->charactersPerTextToken)->toBe(4)
        ->and((string) $sheet->textTokenVariance)->toBe('0.15')
        ->and($sheet->tokensPerReferenceImage)->toBe(765)
        ->and($sheet->minReasoningTokens(ImageQuality::Medium))->toBe(200)
        ->and($sheet->maxReasoningTokens(ImageQuality::Medium))->toBe(2_000);
});

test('knows the exact output tokens for every quality and size', function (ImageQuality $quality, ImageSize $size, int $tokens) {
    $sheet = PriceSheet::fromPricingResponse(Pixelforge::load('pricing'));

    expect($sheet->outputTokens($quality, $size))->toBe($tokens);
})->with([
    'low square' => [ImageQuality::Low, ImageSize::Square, 280],
    'low landscape' => [ImageQuality::Low, ImageSize::Landscape, 420],
    'low portrait' => [ImageQuality::Low, ImageSize::Portrait, 420],
    'medium square' => [ImageQuality::Medium, ImageSize::Square, 1_100],
    'medium landscape' => [ImageQuality::Medium, ImageSize::Landscape, 1_650],
    'medium portrait' => [ImageQuality::Medium, ImageSize::Portrait, 1_650],
    'high square' => [ImageQuality::High, ImageSize::Square, 4_300],
    'high landscape' => [ImageQuality::High, ImageSize::Landscape, 6_450],
    'high portrait' => [ImageQuality::High, ImageSize::Portrait, 6_450],
]);

test('knows the reasoning token range for every quality', function (ImageQuality $quality, int $min, int $max) {
    $sheet = PriceSheet::fromPricingResponse(Pixelforge::load('pricing'));

    expect($sheet->minReasoningTokens($quality))->toBe($min)
        ->and($sheet->maxReasoningTokens($quality))->toBe($max);
})->with([
    'low' => [ImageQuality::Low, 0, 500],
    'medium' => [ImageQuality::Medium, 200, 2_000],
    'high' => [ImageQuality::High, 1_000, 8_000],
]);

test('refuses a pricing response with a missing token rule', function () {
    $response = Pixelforge::load('pricing');
    unset($response['tokens']['output']['high']);

    PriceSheet::fromPricingResponse($response);
})->throws(InvalidArgumentException::class, 'tokens.output.high');
