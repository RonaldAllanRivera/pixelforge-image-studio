<?php

namespace App\Credits;

use RuntimeException;

/**
 * The user's balance can't cover the most a generation could cost, so it wasn't created.
 */
final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly int $neededNano,
        public readonly int $balanceNano,
    ) {
        parent::__construct("A balance of {$balanceNano} nanodollars can't cover a maximum charge of {$neededNano}.");
    }
}
