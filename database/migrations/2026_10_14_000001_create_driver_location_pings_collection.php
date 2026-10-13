<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * MongoDB indexes for the driver location pings (P8-T2): history of one driver by time, and a TTL index that deletes a ping
 * after taxikosmos.driver_location.ping_retention_days. The retention is applied when the index is created; to change it later,
 * drop the `recorded_at` index and migrate again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->table('driver_location_pings', function (Blueprint $collection) {
            $collection->index(['driver_user_id' => 1, 'recorded_at' => -1], 'driver_history');
            $collection->expire('recorded_at', (int) config('taxikosmos.driver_location.ping_retention_days') * 86400);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('driver_location_pings');
    }
};
