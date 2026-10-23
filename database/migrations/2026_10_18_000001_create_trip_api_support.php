<?php

use App\Enums\TripStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the mobile trip API (P7-T6) stands on.
 *
 *  - api_idempotency_keys: the answer to a mutating call, kept for a day under the caller's Idempotency-Key, so a retry after a dropped
 *    connection gets the original answer instead of a second effect.
 *  - trip_driver_cancellations: a driver who cancels before the ride starts sends the trip back to dispatch; this keeps who, when and why
 *    (dispatch, P7-T7, will not offer that trip to the same driver again).
 *  - a partial unique index: a rider can have only one active trip, enforced by the database, so two requests that race past the
 *    "no active trip" check still cannot both create one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            // Method, path and body of the first call: the same key with another request is a mistake, not a retry.
            $table->string('fingerprint', 64);
            // Empty while the first call is still running.
            $table->unsignedSmallInteger('response_status')->nullable();
            // json, not jsonb: a replay must give back the first answer byte for byte (jsonb reorders keys).
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('created_at');
        });

        Schema::create('trip_driver_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('driver_profiles')->restrictOnDelete();
            // The status the trip was in when the driver cancelled (matched or arrived).
            $table->string('trip_status', 32);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_id', 'driver_id']);
        });

        $active = collect([TripStatus::Requested, TripStatus::Matched, TripStatus::Arrived, TripStatus::InProgress])
            ->map(fn (TripStatus $status) => "'{$status->value}'")->implode(', ');

        DB::statement("CREATE UNIQUE INDEX trips_one_active_per_rider ON trips (rider_id) WHERE status IN ({$active})");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trips_one_active_per_rider');
        Schema::dropIfExists('trip_driver_cancellations');
        Schema::dropIfExists('api_idempotency_keys');
    }
};
