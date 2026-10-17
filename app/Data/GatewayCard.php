<?php

namespace App\Data;

/** A card the gateway saved for a customer: its token plus what the app shows ("Visa •••• 4242, 04/28"). Never a card number. */
final readonly class GatewayCard
{
    public function __construct(
        public string $gatewayPaymentMethodId,
        public ?string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
    ) {}
}
