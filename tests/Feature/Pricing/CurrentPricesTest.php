<?php

use App\Pricing\CurrentPrices;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Pixelforge;

test('gets the price sheet from Pixelforge and reuses it for 10 minutes', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);

    app(CurrentPrices::class)->sheet();
    app(CurrentPrices::class)->sheet();
    $this->travel(9)->minutes();
    app(CurrentPrices::class)->sheet();

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    app(CurrentPrices::class)->sheet();

    Http::assertSentCount(2);
});

test('uses the published prices when Pixelforge cannot be reached', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response('', 503)]);

    expect(app(CurrentPrices::class)->sheet()->rates->output)->toBe(40_000);
});

test('uses the published prices when Pixelforge answers with something unreadable', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(['object' => 'pricing'])]);

    expect(app(CurrentPrices::class)->sheet()->rates->output)->toBe(40_000);
});

test('asks Pixelforge again a minute after falling back', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::sequence()
        ->push('', 503)
        ->push(Pixelforge::load('pricing'))]);

    app(CurrentPrices::class)->sheet();
    app(CurrentPrices::class)->sheet();
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    app(CurrentPrices::class)->sheet();
    Http::assertSentCount(2);
});

test('keeps the published fallback prices identical to the real pricing response', function () {
    expect(config('pixelforge.published_pricing'))->toEqual(Pixelforge::load('pricing'));
});
