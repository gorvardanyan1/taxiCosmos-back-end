<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** A ticket got a staff reply, changed status or was assigned (P7-T5). Internal notes never trigger it. */
class SupportTicketUpdated extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketCode,
        public readonly string $status,
        public readonly int $requesterUserId,
        /** One of: reply, status, assignment. */
        public readonly string $change,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::SupportTicketUpdated;
    }

    public function payload(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'ticket_code' => $this->ticketCode,
            'status' => $this->status,
            'requester_user_id' => $this->requesterUserId,
            'change' => $this->change,
        ];
    }
}
