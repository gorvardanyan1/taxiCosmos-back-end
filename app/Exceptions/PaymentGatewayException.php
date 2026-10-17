<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The gateway could not be reached, timed out, or card payments are not set up here (HTTP 503). The outcome of the call is
 * unknown: its transactions row stays `processing` and a retry with the same idempotency key finishes it, never a second charge.
 */
class PaymentGatewayException extends RuntimeException
{
    public static function unreachable(string $detail): self
    {
        return new self($detail);
    }

    public static function notConfigured(string $detail): self
    {
        return new self($detail);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => __('Card payments are not available right now. Try again in a moment.'), 'code' => 'payment_gateway_unavailable'], 503);
    }
}
