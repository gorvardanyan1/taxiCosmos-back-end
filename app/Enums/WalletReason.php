<?php

namespace App\Enums;

/** Why money entered or left a rider's wallet. */
enum WalletReason: string
{
    case RefundToWallet = 'refund_to_wallet';
    case GoodwillCredit = 'goodwill_credit';
    case TopUp = 'top_up';
    case TripPayment = 'trip_payment';
    case Adjustment = 'adjustment';
}
