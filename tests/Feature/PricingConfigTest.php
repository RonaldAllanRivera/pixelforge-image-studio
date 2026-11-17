<?php

use App\Pricing\PricingCalculator;

test('the app prices with the 25% markup from config', function () {
    $calculator = app(PricingCalculator::class);

    expect(config('pricing.markup_percent'))->toBe(25)
        ->and($calculator->charge(100_000_000))->toBe(125_000_000);
});

test('the markup can be changed in config', function () {
    config(['pricing.markup_percent' => 30]);

    expect(app(PricingCalculator::class)->charge(100_000_000))->toBe(130_000_000);
});
