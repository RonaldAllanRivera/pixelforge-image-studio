<?php

use App\Support\Nanodollars;

test('parses a Pixelforge decimal string exactly', function () {
    expect(Nanodollars::fromDecimal('0.169080000'))->toBe(169_080_000)
        ->and(Nanodollars::fromDecimal('0.373875'))->toBe(373_875_000)
        ->and(Nanodollars::fromDecimal('0'))->toBe(0)
        ->and(Nanodollars::fromDecimal('12.5'))->toBe(12_500_000_000);
});

test('refuses amounts smaller than a nanodollar', function () {
    Nanodollars::fromDecimal('0.0000000001');
})->throws(InvalidArgumentException::class);

test('refuses text that is not a number', function () {
    Nanodollars::fromDecimal('abc');
})->throws(InvalidArgumentException::class);

test('writes nanodollars as a 9-decimal string, like Pixelforge', function () {
    expect(Nanodollars::toDecimal(169_080_000))->toBe('0.169080000')
        ->and(Nanodollars::toDecimal(0))->toBe('0.000000000')
        ->and(Nanodollars::toDecimal(12_500_000_000))->toBe('12.500000000');
});

test('formats for display, rounding half up', function () {
    expect(Nanodollars::format(21_562_500))->toBe('$0.0216')
        ->and(Nanodollars::format(21_562_500, 9))->toBe('$0.021562500')
        ->and(Nanodollars::format(0))->toBe('$0.0000')
        ->and(Nanodollars::format(5_000_000_000, 2))->toBe('$5.00');
});

test('adds the markup exactly when the cost divides evenly', function () {
    // The live test generation on 2026-11-17: 10 text, 280 output and 480 reasoning tokens.
    expect(Nanodollars::addMarkup(17_250_000, 25))->toBe(21_562_500);
});

test('rounds a marked-up amount up, never down', function () {
    expect(Nanodollars::addMarkup(1, 25))->toBe(2)  // 1.25 becomes 2, never 1
        ->and(Nanodollars::addMarkup(3, 25))->toBe(4)  // 3.75 becomes 4
        ->and(Nanodollars::addMarkup(4, 25))->toBe(5); // exact
});

test('keeps free generations free', function () {
    expect(Nanodollars::addMarkup(0, 25))->toBe(0);
});

test('refuses to mark up a negative amount', function () {
    Nanodollars::addMarkup(-1, 25);
})->throws(InvalidArgumentException::class);
