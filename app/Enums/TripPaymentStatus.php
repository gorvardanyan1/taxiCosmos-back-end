<?php

namespace App\Enums;

/**
 * Money progress of a trip, tracked apart from the ride status (a completed ride can still be unpaid).
 */
enum TripPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case PaymentFailed = 'payment_failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
