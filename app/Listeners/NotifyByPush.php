<?php

namespace App\Listeners;

use App\Enums\PushType;
use App\Enums\TripStatus;
use App\Enums\UserStatus;
use App\Events\DriverDocumentReviewed;
use App\Events\Realtime\SupportTicketUpdated;
use App\Events\Realtime\TripOffer;
use App\Events\Realtime\TripStatusChanged;
use App\Events\Realtime\UserSuspended;
use App\Services\Push\PushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns domain events into push notifications (P8-T4). The events already fire after the database commit, so a rolled back
 * change never pushes. A failure here is reported but must not undo, or fail the request for, a change that is committed.
 */
class NotifyByPush
{
    public function __construct(private readonly PushNotifier $push) {}

    public function tripOffer(TripOffer $event): void
    {
        $this->send($event->driverUserId, PushType::TripOffer, ['offer_id' => $event->offerId, 'trip_id' => $event->tripId]);
    }

    public function tripStatusChanged(TripStatusChanged $event): void
    {
        $type = match (TripStatus::tryFrom($event->toStatus)) {
            TripStatus::Matched => PushType::TripMatched,
            TripStatus::Arrived => PushType::TripArrived,
            TripStatus::Completed => PushType::TripCompleted,
            TripStatus::NoDriverFound => PushType::NoDriverFound,
            default => null, // requested, in progress, cancelled: no push of its own
        };

        if ($type !== null && $event->riderUserId !== null) {
            $this->send($event->riderUserId, $type, ['trip_id' => $event->tripId]);
        }
    }

    public function userSuspended(UserSuspended $event): void
    {
        // Deactivated and pending-deletion accounts end quietly; only a suspension is announced.
        if ($event->status === UserStatus::Suspended->value) {
            $this->send($event->userId, PushType::AccountSuspended);
        }
    }

    public function supportTicketUpdated(SupportTicketUpdated $event): void
    {
        if ($event->change === 'reply') {
            $this->send($event->requesterUserId, PushType::SupportReply, ['ticket_id' => $event->ticketId]);
        }
    }

    public function documentReviewed(DriverDocumentReviewed $event): void
    {
        $this->send($event->driverUserId, $event->decision->value === 'approved' ? PushType::DocumentApproved : PushType::DocumentRejected, ['document_id' => $event->documentId]);
    }

    /**
     * @param  array<string, int|string>  $data
     */
    private function send(int $userId, PushType $type, array $data = []): void
    {
        try {
            $this->push->notify($userId, $type, $data);
        } catch (Throwable $e) {
            // Ids only: never the payload.
            Log::error('A push notification could not be queued.', ['user_id' => $userId, 'type' => $type->value, 'exception' => $e::class]);
            report($e);
        }
    }
}
