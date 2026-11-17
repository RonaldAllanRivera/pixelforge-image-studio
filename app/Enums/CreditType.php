<?php

namespace App\Enums;

/**
 * What a credit entry did to a balance. A generation holds its maximum when submitted, then
 * releases the hold and, if it succeeded, is charged what it actually cost.
 */
enum CreditType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Hold => 'Held',
            self::Release => 'Released',
            self::Charge => 'Charged',
        };
    }
}
