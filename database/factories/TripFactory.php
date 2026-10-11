<?php

namespace Database\Factories;

use App\Data\GeoPoint;
use App\Enums\TripCancelledBy;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A trip in Yerevan, priced in AMD. The state methods build rows that already satisfy the table's
 * consistency rules (a matched trip has a driver, a completed one has its final fare).
 *
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rider_id' => User::factory()->rider(),
            'zone_id' => Zone::factory(),
            'status' => TripStatus::Requested,
            'pickup_location' => new GeoPoint(40.1772, 44.5035),
            'dropoff_location' => new GeoPoint(40.1872, 44.5152),
            'pickup_address' => 'Republic Square, Yerevan',
            'dropoff_address' => 'Cascade Complex, Yerevan',
            'requested_at' => now(),
            'vehicle_class' => VehicleClass::Economy,
            'payment_method' => TripPaymentMethod::Cash,
            'payment_status' => TripPaymentStatus::Unpaid,
            'fare_currency' => 'AMD',
            'estimated_fare_amount' => 150000,
            'surge_multiplier' => '1.00',
        ];
    }

    public function matched(): static
    {
        return $this->state(fn () => [
            'status' => TripStatus::Matched,
            'driver_id' => DriverProfile::factory()->approved(),
            'matched_at' => now(),
        ]);
    }

    public function arrived(): static
    {
        return $this->matched()->state(fn () => ['status' => TripStatus::Arrived, 'arrived_at' => now()]);
    }

    public function inProgress(): static
    {
        return $this->arrived()->state(fn () => ['status' => TripStatus::InProgress, 'started_at' => now()]);
    }

    /**
     * 1 500.00 AMD fare: 1 200.00 for the driver, 300.00 commission.
     */
    public function completed(): static
    {
        return $this->inProgress()->state(fn () => [
            'status' => TripStatus::Completed,
            'completed_at' => now(),
            'distance_meters' => 4200,
            'duration_seconds' => 780,
            'fare_amount' => 150000,
            'commission_amount' => 30000,
            'driver_earnings_amount' => 120000,
            'fare_breakdown' => ['base' => 50000, 'distance' => 63000, 'time' => 26000, 'waiting' => 0, 'surge' => 0, 'fees' => 11000, 'tax' => 0, 'discount' => 0, 'cancellation_fee' => 0],
        ]);
    }

    public function cancelled(TripCancelledBy $by = TripCancelledBy::Rider, string $reason = 'Changed my mind'): static
    {
        return $this->state(fn () => [
            'status' => TripStatus::Cancelled,
            'cancelled_by' => $by,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
    }

    public function noDriverFound(): static
    {
        return $this->state(fn () => ['status' => TripStatus::NoDriverFound]);
    }
}
