<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Money is stored as whole nanodollars (1 USD = 1,000,000,000) in bigint columns.
 * Pixelforge reports amounts to 9 decimal places, so nanodollars match them exactly.
 *
 * This class converts and formats. The only place money is ever rounded is addMarkup().
 */
final class Nanodollars
{
    public const DECIMALS = 9;

    /**
     * Parse an exact decimal string, such as Pixelforge's "0.169080000", into nanodollars.
     */
    public static function fromDecimal(string $usd): int
    {
        try {
            return BigDecimal::of($usd)->withPointMovedRight(self::DECIMALS)->toBigInteger()->toInt();
        } catch (MathException $e) {
            throw new InvalidArgumentException("[{$usd}] is not an amount in whole nanodollars.", previous: $e);
        }
    }

    /**
     * Write nanodollars as a 9-decimal string, the way Pixelforge reports money.
     */
    public static function toDecimal(int $nanodollars): string
    {
        return (string) BigDecimal::ofUnscaledValue($nanodollars, self::DECIMALS);
    }

    /**
     * Format an amount for people to read, such as "$0.0216". Display only: never store it.
     *
     * @param  int<0, 9>  $decimals  Nanodollars have 9 decimals, so more would add nothing.
     */
    public static function format(int $nanodollars, int $decimals = 4): string
    {
        return '$'.BigDecimal::ofUnscaledValue($nanodollars, self::DECIMALS)->toScale($decimals, RoundingMode::HalfUp);
    }

    /**
     * Format a maximum ("never more than"), rounding up, so the amount shown is never below
     * the real cap the charge can reach. Display only: never store it.
     *
     * @param  int<0, 9>  $decimals
     */
    public static function formatAtMost(int $nanodollars, int $decimals = 4): string
    {
        return '$'.BigDecimal::ofUnscaledValue($nanodollars, self::DECIMALS)->toScale($decimals, RoundingMode::Up);
    }

    /**
     * Format a balance, rounding down, so the page never shows more than the user has.
     * Display only: never store it.
     *
     * @param  int<0, 9>  $decimals
     */
    public static function formatRoundedDown(int $nanodollars, int $decimals = 4): string
    {
        return '$'.BigDecimal::ofUnscaledValue($nanodollars, self::DECIMALS)->toScale($decimals, RoundingMode::Down);
    }

    /**
     * Add a percentage markup, rounded up to the next whole nanodollar so rounding never
     * loses money. This is the only rounding of money in the app.
     */
    public static function addMarkup(int $nanodollars, int $percent): int
    {
        if ($nanodollars < 0 || $percent < 0) {
            throw new InvalidArgumentException('A markup needs a non-negative amount and percentage.');
        }

        return intdiv($nanodollars * (100 + $percent) + 99, 100);
    }
}
