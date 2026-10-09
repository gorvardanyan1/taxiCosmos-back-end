<?php

use App\Enums\VehicleClass;
use App\Enums\VehicleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('driver_profiles')->restrictOnDelete();
            $table->string('make', 64);
            $table->string('model', 64);
            $table->unsignedSmallInteger('year');
            $table->string('plate_number', 20)->index();
            $table->string('color', 32);
            $table->string('vehicle_class', 32)->index();
            $table->string('status', 32)->default(VehicleStatus::PendingReview->value);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            // Soft deletes: trips keep referencing a vehicle the driver removed.
            $table->softDeletes();

            $table->index(['driver_id', 'status']);
        });

        $classes = implode(', ', array_map(fn (VehicleClass $c) => "'{$c->value}'", VehicleClass::cases()));
        $statuses = implode(', ', array_map(fn (VehicleStatus $s) => "'{$s->value}'", VehicleStatus::cases()));

        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_vehicle_class_check CHECK (vehicle_class IN ({$classes}))");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_year_check CHECK (year BETWEEN 1900 AND 2100)');

        // At most one primary (non-deleted) vehicle per driver.
        DB::statement('CREATE UNIQUE INDEX vehicles_one_primary_per_driver ON vehicles (driver_id) WHERE is_primary AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
