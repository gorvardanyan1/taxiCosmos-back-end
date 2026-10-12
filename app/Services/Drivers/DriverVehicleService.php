<?php

namespace App\Services\Drivers;

use App\Enums\VehicleStatus;
use App\Exceptions\DriverStateException;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * A driver's vehicles as admins manage them (drivers.edit). Every change is one transaction under the
 * driver's row lock (the lock markAsPrimary uses) with its audit entry. A vehicle an admin adds is active
 * and becomes the primary one when the driver has none; a vehicle that stops being active cannot stay primary.
 */
final class DriverVehicleService
{
    private const FIELDS = ['make', 'model', 'year', 'color', 'plate_number', 'vehicle_class', 'status'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{make: string, model: string, year: int, color: string, plate_number: string, vehicle_class: string, status?: string}  $data
     */
    public function add(User $actor, DriverProfile $driver, array $data): Vehicle
    {
        return DB::transaction(function () use ($actor, $driver, $data) {
            $locked = DriverProfile::query()->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();

            $vehicle = new Vehicle(array_intersect_key($data, array_flip(['make', 'model', 'year', 'color', 'plate_number', 'vehicle_class'])));
            $vehicle->forceFill(['driver_id' => $locked->id, 'status' => $data['status'] ?? VehicleStatus::Active->value])->save();

            if ($vehicle->status === VehicleStatus::Active && ! Vehicle::query()->where('driver_id', $locked->id)->where('is_primary', true)->exists()) {
                $vehicle->markAsPrimary();
            }

            $this->audit->record(actor: $actor, action: 'driver.vehicle.added', target: $vehicle, new: $this->snapshot($vehicle->refresh()), context: ['driver' => sprintf('D-%04d', $locked->id)]);

            return $vehicle;
        });
    }

    /**
     * Only the fields present change. Returns false when nothing differed (no write, no audit entry).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, DriverProfile $driver, Vehicle $vehicle, array $data): bool
    {
        return DB::transaction(function () use ($actor, $driver, $vehicle, $data) {
            DriverProfile::query()->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
            $locked = Vehicle::query()->where('driver_id', $driver->getKey())->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            $locked->forceFill(array_intersect_key($data, array_flip(self::FIELDS)));

            // A vehicle that is no longer active cannot be the one the driver works with.
            if ($locked->isDirty('status') && $locked->status !== VehicleStatus::Active && $locked->is_primary) {
                $locked->is_primary = false;
            }

            if (! $locked->isDirty()) {
                return false;
            }

            $locked->save();
            $after = $this->snapshot($locked->refresh());

            $this->audit->record(
                actor: $actor, action: 'driver.vehicle.updated', target: $locked,
                old: array_diff_assoc($before, $after), new: array_diff_assoc($after, $before), context: ['driver' => sprintf('D-%04d', $driver->getKey())],
            );

            return true;
        });
    }

    public function setPrimary(User $actor, DriverProfile $driver, Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($actor, $driver, $vehicle) {
            DriverProfile::query()->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
            $locked = Vehicle::query()->where('driver_id', $driver->getKey())->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_primary) {
                throw DriverStateException::alreadyPrimary();
            }

            if ($locked->status !== VehicleStatus::Active) {
                throw DriverStateException::vehicleNotActive();
            }

            $previous = Vehicle::query()->where('driver_id', $driver->getKey())->where('is_primary', true)->value('id');
            $locked->markAsPrimary();

            $this->audit->record(
                actor: $actor, action: 'driver.vehicle.primary_set', target: $locked,
                old: ['primary_vehicle_id' => $previous], new: ['primary_vehicle_id' => $locked->id], context: ['driver' => sprintf('D-%04d', $driver->getKey())],
            );

            return $locked->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Vehicle $vehicle): array
    {
        return [
            'make' => $vehicle->make, 'model' => $vehicle->model, 'year' => $vehicle->year, 'color' => $vehicle->color,
            'plate_number' => $vehicle->plate_number, 'vehicle_class' => $vehicle->vehicle_class->value,
            'status' => $vehicle->status->value, 'is_primary' => $vehicle->is_primary,
        ];
    }
}
