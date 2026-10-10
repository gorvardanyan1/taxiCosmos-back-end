<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Stable short code used by fare rules, surge, commission rules and reports; never changes.
            $table->string('code', 32)->unique();
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true)->index();
            // Overlapping zones: the highest priority wins (ties: the oldest zone).
            $table->smallInteger('priority')->default(0);
            $table->timestamps();
        });

        // The service area. MultiPolygon so one zone can have several areas (islands, exclaves).
        DB::statement('ALTER TABLE zones ADD COLUMN polygon geometry(MultiPolygon, 4326) NOT NULL');
        DB::statement('CREATE INDEX zones_polygon_gist ON zones USING GIST (polygon)');
        // Defence in depth behind the validation: an invalid or empty shape can never be stored.
        DB::statement('ALTER TABLE zones ADD CONSTRAINT zones_polygon_valid_check CHECK (ST_IsValid(polygon) AND NOT ST_IsEmpty(polygon))');
        DB::statement('ALTER TABLE zones ADD CONSTRAINT zones_priority_check CHECK (priority BETWEEN 0 AND 1000)');

        // The driver's home zone (added by P2-T2 without a foreign key until this table existed).
        // Zones are deactivated, never deleted, so the reference is restricted.
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreign('home_zone_id')->references('id')->on('zones')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropForeign(['home_zone_id']);
        });
        Schema::dropIfExists('zones');
    }
};
