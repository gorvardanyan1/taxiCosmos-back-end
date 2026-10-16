<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A payment the rider's (or the transaction's) current state does not allow (P6-T2). The apps branch on the stable `code`:
 * a declined card is 402, every state conflict is 409.
 */
class PaymentException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function declined(): self
    {
        return new self(__('The card was declined. Try another card.'), 'card_declined', 402);
    }

    public static function outstandingBalance(): self
    {
        return new self(__('Pay your outstanding balance before requesting a card trip.'), 'outstanding_balance');
    }

    public static function nothingOutstanding(): self
    {
        return new self(__('There is nothing to pay.'), 'nothing_outstanding');
    }

    public static function noCard(): self
    {
        return new self(__('Add a card first.'), 'no_payment_method');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(__('This idempotency key was already used for a different payment.'), 'idempotency_key_reused');
    }

    public static function inProgress(): self
    {
        return new self(__('This payment is still being processed. Try again in a moment.'), 'payment_in_progress');
    }

    public static function holdNotOpen(): self
    {
        return new self(__('This hold has already been captured or released.'), 'hold_not_open');
    }

    public static function notRefundable(): self
    {
        return new self(__('Only a succeeded charge or capture can be refunded.'), 'not_refundable');
    }

    public static function refundExceedsRemaining(): self
    {
        return new self(__('The refund is more than what is left to refund.'), 'refund_exceeds_remaining');
    }

    public static function cardInUse(): self
    {
        return new self(__('This card is paying for a trip in progress. Remove it after the trip.'), 'card_in_use');
    }

    public static function cardLimit(): self
    {
        return new self(__('You have reached the number of cards you can keep. Remove one first.'), 'card_limit_reached');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
