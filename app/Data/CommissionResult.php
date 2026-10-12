<?php

namespace App\Data;

/**
 * What the platform and the driver each get from one fare, in integer minor units of one currency.
 * `base` is the part of the fare the commission was taken from (the fare without tax, and without
 * surge when the rule leaves surge out); `taxFree` is everything the driver and platform share.
 *
 *   driverEarnings = taxFree - commission
 */
final readonly class CommissionResult
{
    public function __construct(
        public string $currency,
        public int $taxFree,
        public int $base,
        public int $commission,
        public int $driverEarnings,
        public ?int $ruleId = null,
    ) {}
}
