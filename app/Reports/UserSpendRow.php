<?php

namespace App\Reports;

/**
 * One account's succeeded generations in the report's range. A deleted account shows as
 * "Deleted account", with no email; its generations stay, as billing records.
 */
final readonly class UserSpendRow
{
    public function __construct(
        public string $name,
        public ?string $maskedEmail,
        public int $generations,
        public int $chargedNano,
        public int $costNano,
    ) {}
}
