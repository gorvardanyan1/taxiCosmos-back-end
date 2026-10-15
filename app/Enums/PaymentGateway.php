<?php

namespace App\Enums;

/** Where a rider's money moved through. `manual` is cash or a bank transfer an admin recorded by hand; `wallet` is the rider's platform balance. */
enum PaymentGateway: string
{
    case Stripe = 'stripe';
    case AuthorizeNet = 'authorize_net';
    case Manual = 'manual';
    case Wallet = 'wallet';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
