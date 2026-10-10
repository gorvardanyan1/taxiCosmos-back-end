<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** A payment/refund changed status (P6). Sent to the user who paid or was refunded. */
class TransactionStatusChanged extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $tripId,
        public readonly int $userId,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly int $amount,
        public readonly string $currency,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::TransactionStatusChanged;
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'trip_id' => $this->tripId,
            'user_id' => $this->userId,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'amount' => self::money($this->amount, $this->currency),
        ];
    }
}
