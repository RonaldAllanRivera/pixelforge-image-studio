<?php

use App\Support\MaskedEmail;

test('keeps the first two characters and the domain', function () {
    expect(MaskedEmail::mask('allan@gmail.com'))->toBe('al•••@gmail.com');
});

test('masks a one-character address without failing', function () {
    expect(MaskedEmail::mask('a@x.com'))->toBe('a•••@x.com');
});

test('counts characters, not bytes, so accented names stay readable', function () {
    expect(MaskedEmail::mask('émile@exemple.fr'))->toBe('ém•••@exemple.fr');
});

test('never shows the whole of something that is not an email address', function () {
    expect(MaskedEmail::mask('not-an-email'))->toBe('no•••');
});
