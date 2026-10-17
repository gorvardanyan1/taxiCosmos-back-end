<?php

namespace App\Data;

use App\Enums\TransactionStatus;

/**
 * What a gateway answered, the same shape for every gateway (P6-T2): the status in our terms, the gateway's own id for the
 * transaction, and its raw answer (trimmed by the gateway class to what is safe to keep: no card data, no personal data).
 */
final readonly class PaymentResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public TransactionStatus $status,
        public ?string $gatewayReference,
        public array $rawResponse = [],
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function succeeded(string $gatewayReference, array $rawResponse = []): self
    {
        return new self(TransactionStatus::Succeeded, $gatewayReference, $rawResponse);
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function failed(?string $gatewayReference, string $failureCode, string $failureMessage, array $rawResponse = []): self
    {
        return new self(TransactionStatus::Failed, $gatewayReference, $rawResponse, $failureCode, $failureMessage);
    }
}
