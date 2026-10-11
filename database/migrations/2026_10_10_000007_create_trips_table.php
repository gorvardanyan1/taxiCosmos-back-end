<?php

use App\Enums\TripCancelledBy;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            // Short human-readable reference shown in the admin UI and to riders and drivers.
            $table->string('public_code', 16)->unique();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            // Empty until a driver accepts; emptied again when the trip goes back to dispatch.
            $table->foreignId('driver_id')->nullable()->constrained('driver_profiles')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();

            $table->string('status', 32)->default(TripStatus::Requested->value);
            $table->string('cancelled_by', 16)->nullable();

            $table->string('pickup_address', 255);
            $table->string('dropoff_address', 255);

            $table->timestamp('requested_at');
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->integer('distance_meters')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('vehicle_class', 32);

            $table->string('payment_method', 16);
            // Reference to the rider's saved card (payment_methods arrives with the payments schema).
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('payment_status', 32)->default(TripPaymentStatus::Unpaid->value);

            // Pricing snapshot (money rules): integers in minor units. Copied from the rules the trip
            // was priced with, so editing a rule later never changes a historical trip. The rule ids
            // are references for audit only; their tables (P5-T2, P6-T4) add the foreign keys.
            $table->char('fare_currency', 3);
            $table->bigInteger('estimated_fare_amount');
            $table->bigInteger('fare_amount')->nullable();
            $table->unsignedBigInteger('fare_rule_id')->nullable();
            $table->unsignedBigInteger('commission_rule_id')->nullable();
            $table->decimal('surge_multiplier', 4, 2)->default('1.00');
            $table->jsonb('fare_breakdown')->nullable();
            $table->bigInteger('commission_amount')->nullable();
            $table->bigInteger('driver_earnings_amount')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index(['rider_id', 'requested_at']);
            $table->index(['driver_id', 'requested_at']);
            $table->index(['zone_id', 'requested_at']);
        });

        DB::statement('ALTER TABLE trips ADD COLUMN pickup_location geography(Point, 4326) NOT NULL');
        DB::statement('ALTER TABLE trips ADD COLUMN dropoff_location geography(Point, 4326) NOT NULL');
        DB::statement('CREATE INDEX trips_pickup_location_gist ON trips USING GIST (pickup_location)');
        DB::statement('CREATE INDEX trips_dropoff_location_gist ON trips USING GIST (dropoff_location)');

        $this->enumCheck('status', TripStatus::values());
        $this->enumCheck('cancelled_by', array_column(TripCancelledBy::cases(), 'value'), nullable: true);
        $this->enumCheck('vehicle_class', array_column(VehicleClass::cases(), 'value'));
        $this->enumCheck('payment_method', array_column(TripPaymentMethod::cases(), 'value'));
        $this->enumCheck('payment_status', array_column(TripPaymentStatus::cases(), 'value'));

        // Defence in depth behind the model and TripStatus: a row can never contradict its status.
        $constraints = [
            'surge_multiplier_check' => 'surge_multiplier >= 1.00',
            'cancelled_by_status_check' => "(status = 'cancelled') = (cancelled_by IS NOT NULL)",
            'cancelled_at_status_check' => "(status = 'cancelled') = (cancelled_at IS NOT NULL)",
            // PostgreSQL has no unsigned integers: amounts and measurements are checked instead.
            'amounts_check' => 'estimated_fare_amount >= 0 AND COALESCE(fare_amount, 0) >= 0 AND COALESCE(commission_amount, 0) >= 0 AND COALESCE(driver_earnings_amount, 0) >= 0',
            'measurements_check' => 'COALESCE(distance_meters, 0) >= 0 AND COALESCE(duration_seconds, 0) >= 0',
            'driver_assigned_check' => "status NOT IN ('matched', 'arrived', 'in_progress', 'completed') OR driver_id IS NOT NULL",
            'completion_check' => "status <> 'completed' OR (completed_at IS NOT NULL AND fare_amount IS NOT NULL AND commission_amount IS NOT NULL AND driver_earnings_amount IS NOT NULL)",
            'earnings_check' => 'commission_amount IS NULL OR driver_earnings_amount IS NULL OR fare_amount IS NULL OR commission_amount + driver_earnings_amount <= fare_amount',
        ];

        foreach ($constraints as $name => $expression) {
            DB::statement("ALTER TABLE trips ADD CONSTRAINT trips_{$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }

    /**
     * @param  list<string>  $values
     */
    private function enumCheck(string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'{$v}'", $values));
        $condition = $nullable ? "{$column} IS NULL OR {$column} IN ({$list})" : "{$column} IN ({$list})";

        DB::statement("ALTER TABLE trips ADD CONSTRAINT trips_{$column}_check CHECK ({$condition})");
    }
};
