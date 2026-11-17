<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

test('tests cannot make real HTTP requests', function () {
    expect(fn () => Http::get('https://pixelforge.promptedit.dev/v1/pricing'))
        ->toThrow(StrayRequestException::class);
});
