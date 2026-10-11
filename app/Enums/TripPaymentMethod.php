<?php

namespace App\Enums;

enum TripPaymentMethod: string
{
    case Card = 'card';
    case Cash = 'cash';
    case Wallet = 'wallet';
}
