<?php

namespace App\Enums;

/**
 * The ride lifecycle, and the one place that says which moves are allowed. The mobile API, admin
 * interventions and tests all ask canTransitionTo(); nothing else decides.
 *
 * "matched" means a driver accepted and is on the way to the pickup (there is no "arriving" status).
 * Payment progress is a separate field (TripPaymentStatus).
 */
enum TripStatus: string
{
    case Requested = 'requested';
    case Matched = 'matched';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoDriverFound = 'no_driver_found';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Matched, self::NoDriverFound, self::Cancelled],
            // Back to requested: admin reassign, or the driver cancels and the trip returns to dispatch.
            self::Matched => [self::Arrived, self::Requested, self::Cancelled],
            self::Arrived => [self::InProgress, self::Requested, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::NoDriverFound => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Completed, cancelled and no-driver trips never change status again.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Who may cancel a trip that is in this status: the rider, the driver (once matched) and the
     * system until the ride starts; only an admin can force-cancel a ride in progress.
     */
    public function canBeCancelledBy(TripCancelledBy $by): bool
    {
        return match ($this) {
            self::Requested => $by !== TripCancelledBy::Driver,
            self::Matched, self::Arrived => true,
            self::InProgress => $by === TripCancelledBy::Admin,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
