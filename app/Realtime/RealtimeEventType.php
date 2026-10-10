<?php

namespace App\Realtime;

/**
 * Every event Laravel publishes to the real-time service: its stream, schema version and the
 * keys of its payload. This enum is the single source of truth, and docs/realtime-events.md is
 * tested against it. Bump version() (and document it) whenever a payload changes incompatibly.
 */
enum RealtimeEventType: string
{
    case TripOffer = 'trip.offer';
    case TripOfferCancelled = 'trip.offer_cancelled';
    case TripStatusChanged = 'trip.status_changed';
    case TripReassigned = 'trip.reassigned';
    case TripFareAdjusted = 'trip.fare_adjusted';
    case TransactionStatusChanged = 'transaction.status_changed';
    case DriverVerificationChanged = 'driver.verification_changed';
    case DriverAvailabilityChanged = 'driver.availability_changed';
    case UserSuspended = 'user.suspended';
    case SurgeChanged = 'surge.changed';
    case SupportTicketUpdated = 'support_ticket.updated';
    case DriverLocationUpdated = 'driver.location_updated';

    /** One stream per event type (see docs/realtime-events.md for why). */
    public function streamKey(): string
    {
        return config('realtime.stream_prefix').$this->value;
    }

    /** Schema version of the payload; consumers must ignore versions they do not know. */
    public function version(): int
    {
        return 1;
    }

    /**
     * Keys of the payload object, in the documented order. Nested objects ("pickup", money
     * values) are documented in docs/realtime-events.md.
     *
     * @return list<string>
     */
    public function payloadKeys(): array
    {
        return match ($this) {
            self::TripOffer => ['offer_id', 'trip_id', 'driver_user_id', 'expires_at', 'pickup_address', 'dropoff_address', 'estimated_fare', 'vehicle_class'],
            self::TripOfferCancelled => ['offer_id', 'trip_id', 'driver_user_id', 'reason'],
            self::TripStatusChanged => ['trip_id', 'trip_code', 'from_status', 'to_status', 'rider_user_id', 'driver_user_id'],
            self::TripReassigned => ['trip_id', 'trip_code', 'rider_user_id', 'previous_driver_user_id', 'new_driver_user_id'],
            self::TripFareAdjusted => ['trip_id', 'trip_code', 'rider_user_id', 'driver_user_id', 'previous_fare', 'new_fare'],
            self::TransactionStatusChanged => ['transaction_id', 'trip_id', 'user_id', 'from_status', 'to_status', 'amount'],
            self::DriverVerificationChanged => ['driver_user_id', 'driver_profile_id', 'from_status', 'to_status'],
            self::DriverAvailabilityChanged => ['driver_user_id', 'driver_profile_id', 'from_availability', 'to_availability'],
            self::UserSuspended => ['user_id', 'status'],
            self::SurgeChanged => ['surge_id', 'zone_id', 'vehicle_class', 'multiplier', 'active', 'starts_at', 'ends_at'],
            self::SupportTicketUpdated => ['ticket_id', 'ticket_code', 'status', 'requester_user_id', 'change'],
            self::DriverLocationUpdated => ['driver_user_id', 'latitude', 'longitude', 'heading', 'recorded_at'],
        };
    }
}
