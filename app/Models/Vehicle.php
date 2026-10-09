<?php

namespace App\Models;

use App\Enums\VehicleClass;
use App\Enums\VehicleStatus;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A driver's vehicle. Status (review) and is_primary are not mass-assignable;
 * at most one primary vehicle per driver is enforced by a partial unique index.
 */
#[Fillable(['make', 'model', 'year', 'plate_number', 'color', 'vehicle_class'])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => VehicleStatus::PendingReview->value,
        'is_primary' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'vehicle_class' => VehicleClass::class,
            'status' => VehicleStatus::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DriverProfile, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    /**
     * Make this the driver's only primary vehicle. The driver profile row is locked so two
     * concurrent switches serialise instead of tripping the partial unique index.
     */
    public function markAsPrimary(): void
    {
        DB::transaction(function () {
            DriverProfile::query()->whereKey($this->driver_id)->lockForUpdate()->first();

            static::query()
                ->where('driver_id', $this->driver_id)
                ->whereKeyNot($this->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $this->forceFill(['is_primary' => true])->save();
        });
    }
}
