<?php

use App\Pixelforge\WebhookSignature;

const SIGNATURE_TEST_SECRET = 'whsec_unit_test';
const SIGNATURE_TEST_NOW = 1_795_000_000;

function signatureHeader(string $body, int $timestamp = SIGNATURE_TEST_NOW, string $secret = SIGNATURE_TEST_SECRET): string
{
    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

test('accepts a body signed with our secret', function () {
    $signature = new WebhookSignature(SIGNATURE_TEST_SECRET);

    expect($signature->isValid(signatureHeader('{"id":"evt_1"}'), '{"id":"evt_1"}', SIGNATURE_TEST_NOW))->toBeTrue();
});

test('rejects a body signed with another secret', function () {
    $signature = new WebhookSignature(SIGNATURE_TEST_SECRET);

    expect($signature->isValid(signatureHeader('{"id":"evt_1"}', secret: 'whsec_someone_else'), '{"id":"evt_1"}', SIGNATURE_TEST_NOW))->toBeFalse();
});

test('rejects a body changed after signing', function () {
    $signature = new WebhookSignature(SIGNATURE_TEST_SECRET);

    expect($signature->isValid(signatureHeader('{"amount":1}'), '{"amount":9}', SIGNATURE_TEST_NOW))->toBeFalse();
});

test('accepts timestamps up to 5 minutes away and rejects older or newer ones', function (int $offset, bool $valid) {
    $signature = new WebhookSignature(SIGNATURE_TEST_SECRET);
    $header = signatureHeader('{}', SIGNATURE_TEST_NOW + $offset);

    expect($signature->isValid($header, '{}', SIGNATURE_TEST_NOW))->toBe($valid);
})->with([
    'just now' => [0, true],
    '300 s old' => [-300, true],
    '301 s old (a replay)' => [-301, false],
    '301 s in the future' => [301, false],
]);

test('rejects a missing or malformed header', function (string $header) {
    expect((new WebhookSignature(SIGNATURE_TEST_SECRET))->isValid($header, '{}', SIGNATURE_TEST_NOW))->toBeFalse();
})->with([
    'empty' => [''],
    'no timestamp' => ['v1=abc'],
    'no signature' => ['t='.SIGNATURE_TEST_NOW],
    'not a number' => ['t=soon,v1=abc'],
    'garbage' => ['hello'],
]);

test('accepts the header when any of several signatures matches, as during a secret rotation', function () {
    $signature = new WebhookSignature(SIGNATURE_TEST_SECRET);
    $good = signatureHeader('{}');

    expect($signature->isValid($good.',v1=deadbeef', '{}', SIGNATURE_TEST_NOW))->toBeTrue()
        ->and($signature->isValid('t='.SIGNATURE_TEST_NOW.',v1=deadbeef,'.substr($good, strpos($good, 'v1=')), '{}', SIGNATURE_TEST_NOW))->toBeTrue();
});

test('rejects everything when no secret is configured', function () {
    expect((new WebhookSignature(null))->isValid(signatureHeader('{}', secret: ''), '{}', SIGNATURE_TEST_NOW))->toBeFalse();
});
