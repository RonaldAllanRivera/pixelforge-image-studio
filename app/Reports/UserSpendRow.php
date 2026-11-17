<?php

namespace App\Reports;

/**
 * One account's succeeded generations in the report's range. A deleted account has no id
 * and no email; its generations stay, as billing records.
 */
final readonly class UserSpendRow
{
    public function __construct(
        public ?int $userId,
        public string $name,
        public ?string $maskedEmail,
        public int $generations,
        public int $chargedNano,
        public int $costNano,
    ) {}

    public function profitNano(): int
    {
        return $this->chargedNano - $this->costNano;
    }
}
