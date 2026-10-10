<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/**
 * The user can no longer use the apps: the socket service must disconnect all of their sockets.
 * Raised by UserObserver when users.status changes to anything other than "active"
 * (suspended, deactivated, pending_deletion). The suspension reason is internal and not published.
 */
class UserSuspended extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $status,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::UserSuspended;
    }

    public function payload(): array
    {
        return ['user_id' => $this->userId, 'status' => $this->status];
    }
}
