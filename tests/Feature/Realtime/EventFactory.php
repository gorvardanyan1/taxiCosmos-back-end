<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\DriverAvailabilityChanged;
use App\Events\Realtime\DriverLocationUpdated;
use App\Events\Realtime\DriverVerificationChanged;
use App\Events\Realtime\RealtimeDomainEvent;
use App\Events\Realtime\SupportTicketUpdated;
use App\Events\Realtime\SurgeChanged;
use App\Events\Realtime\TransactionStatusChanged;
use App\Events\Realtime\TripFareAdjusted;
use App\Events\Realtime\TripOffer;
use App\Events\Realtime\TripOfferCancelled;
use App\Events\Realtime\TripReassigned;
use App\Events\Realtime\TripStatusChanged;
use App\Events\Realtime\UserSuspended;
use App\Realtime\RealtimeEventType;
use Carbon\CarbonImmutable;

/** One realistic instance of every event type, with the exact payload it must produce. */
class EventFactory
{
    /**
     * @return array<string, array{RealtimeEventType, RealtimeDomainEvent, array<string, mixed>}>
     */
    public static function all(): array
    {
        $expires = CarbonImmutable::parse('2026-10-10 09:16:00.250', 'UTC');
        $start = CarbonImmutable::parse('2026-10-10 18:00:00', 'Asia/Yerevan');

        return [
            'trip.offer' => [RealtimeEventType::TripOffer, new TripOffer(11, 501, 42, $expires, 'Republic Square', 'EVN Airport', 12500, 'AMD', 'comfort'), [
                'offer_id' => 11, 'trip_id' => 501, 'driver_user_id' => 42, 'expires_at' => '2026-10-10T09:16:00.250Z',
                'pickup_address' => 'Republic Square', 'dropoff_address' => 'EVN Airport',
                'estimated_fare' => ['amount' => 12500, 'currency' => 'AMD'], 'vehicle_class' => 'comfort',
            ]],
            'trip.offer_cancelled' => [RealtimeEventType::TripOfferCancelled, new TripOfferCancelled(11, 501, 42, 'accepted_by_other'), [
                'offer_id' => 11, 'trip_id' => 501, 'driver_user_id' => 42, 'reason' => 'accepted_by_other',
            ]],
            'trip.status_changed' => [RealtimeEventType::TripStatusChanged, new TripStatusChanged(501, 'TK-8F3K2', 'matched', 'arrived', 7, 42), [
                'trip_id' => 501, 'trip_code' => 'TK-8F3K2', 'from_status' => 'matched', 'to_status' => 'arrived', 'rider_user_id' => 7, 'driver_user_id' => 42,
            ]],
            'trip.reassigned' => [RealtimeEventType::TripReassigned, new TripReassigned(501, 'TK-8F3K2', 7, 42, 43), [
                'trip_id' => 501, 'trip_code' => 'TK-8F3K2', 'rider_user_id' => 7, 'previous_driver_user_id' => 42, 'new_driver_user_id' => 43,
            ]],
            'trip.fare_adjusted' => [RealtimeEventType::TripFareAdjusted, new TripFareAdjusted(501, 'TK-8F3K2', 7, 42, 12500, 11000, 'AMD'), [
                'trip_id' => 501, 'trip_code' => 'TK-8F3K2', 'rider_user_id' => 7, 'driver_user_id' => 42,
                'previous_fare' => ['amount' => 12500, 'currency' => 'AMD'], 'new_fare' => ['amount' => 11000, 'currency' => 'AMD'],
            ]],
            'transaction.status_changed' => [RealtimeEventType::TransactionStatusChanged, new TransactionStatusChanged(9001, 501, 7, 'pending', 'completed', 12500, 'AMD'), [
                'transaction_id' => 9001, 'trip_id' => 501, 'user_id' => 7, 'from_status' => 'pending', 'to_status' => 'completed',
                'amount' => ['amount' => 12500, 'currency' => 'AMD'],
            ]],
            'driver.verification_changed' => [RealtimeEventType::DriverVerificationChanged, new DriverVerificationChanged(42, 5, 'pending', 'approved'), [
                'driver_user_id' => 42, 'driver_profile_id' => 5, 'from_status' => 'pending', 'to_status' => 'approved',
            ]],
            'driver.availability_changed' => [RealtimeEventType::DriverAvailabilityChanged, new DriverAvailabilityChanged(42, 5, 'offline', 'online'), [
                'driver_user_id' => 42, 'driver_profile_id' => 5, 'from_availability' => 'offline', 'to_availability' => 'online',
            ]],
            'user.suspended' => [RealtimeEventType::UserSuspended, new UserSuspended(42, 'suspended'), ['user_id' => 42, 'status' => 'suspended']],
            'surge.changed' => [RealtimeEventType::SurgeChanged, new SurgeChanged(3, 1, 'comfort', '1.50', true, $start, $start->addHours(2)), [
                'surge_id' => 3, 'zone_id' => 1, 'vehicle_class' => 'comfort', 'multiplier' => '1.50', 'active' => true,
                'starts_at' => '2026-10-10T14:00:00.000Z', 'ends_at' => '2026-10-10T16:00:00.000Z',
            ]],
            'support_ticket.updated' => [RealtimeEventType::SupportTicketUpdated, new SupportTicketUpdated(77, 'TKT-2941', 'in_progress', 7, 'reply'), [
                'ticket_id' => 77, 'ticket_code' => 'TKT-2941', 'status' => 'in_progress', 'requester_user_id' => 7, 'change' => 'reply',
            ]],
            'driver.location_updated' => [RealtimeEventType::DriverLocationUpdated, new DriverLocationUpdated(42, 40.1777, 44.5126, 270, $expires), [
                'driver_user_id' => 42, 'latitude' => 40.1777, 'longitude' => 44.5126, 'heading' => 270, 'recorded_at' => '2026-10-10T09:16:00.250Z',
            ]],
        ];
    }
}
