<?php

use App\Enums\VehicleClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Everything on a fare rule except the moment it stops being current. */
    private const FROZEN_RULE_COLUMNS = [
        'zone_id', 'vehicle_class', 'base_fare', 'per_km', 'per_minute', 'min_fare', 'waiting_free_minutes', 'waiting_per_minute',
        'cancellation_fee', 'cancellation_free_seconds', 'booking_fee', 'tax_rate_bps', 'tax_inclusive', 'currency', 'effective_from', 'created_by', 'created_at',
    ];

    public function up(): void
    {
        // btree_gist lets one exclusion constraint compare "same zone and class" (=) with "overlapping period" (&&).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('fare_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            $table->string('vehicle_class', 32);
            // Money: integers in minor units of `currency` (the zone's currency).
            $table->bigInteger('base_fare');
            $table->bigInteger('per_km');
            $table->bigInteger('per_minute');
            $table->bigInteger('min_fare');
            $table->unsignedSmallInteger('waiting_free_minutes');
            $table->bigInteger('waiting_per_minute');
            $table->bigInteger('cancellation_fee');
            $table->unsignedInteger('cancellation_free_seconds');
            $table->bigInteger('booking_fee');
            // Basis points: 1800 = 18 %, 0 = no tax.
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->char('currency', 3);
            // A rule applies from effective_from (inclusive) to effective_to (exclusive); null = still current.
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->default(DB::raw("(now() AT TIME ZONE 'UTC')"));

            $table->index(['zone_id', 'vehicle_class', 'effective_from']);
        });

        $this->vehicleClassCheck('fare_rules');

        $constraints = [
            'amounts_check' => 'base_fare >= 0 AND per_km >= 0 AND per_minute >= 0 AND min_fare >= 0 AND waiting_per_minute >= 0 AND cancellation_fee >= 0 AND booking_fee >= 0',
            'tax_check' => 'tax_rate_bps BETWEEN 0 AND 10000',
            'period_check' => 'effective_to IS NULL OR effective_to > effective_from',
        ];

        foreach ($constraints as $name => $expression) {
            DB::statement("ALTER TABLE fare_rules ADD CONSTRAINT fare_rules_{$name} CHECK ({$expression})");
        }

        // The history can never answer two ways: one zone and class has at most one rule at any instant.
        DB::statement('ALTER TABLE fare_rules ADD CONSTRAINT fare_rules_no_overlap EXCLUDE USING gist (zone_id WITH =, vehicle_class WITH =, tsrange(effective_from, effective_to) WITH &&)');

        // Rules are versioned, never edited: the only change a row allows is closing it once, and a
        // closed rule or a rule a trip was priced with can never be deleted.
        $same = implode(' AND ', array_map(fn (string $column) => "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}", self::FROZEN_RULE_COLUMNS));
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fare_rules_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Fare rules are history and cannot be deleted.' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NOT ({$same}) THEN
                    RAISE EXCEPTION 'Fare rules are never edited: add a new version instead.' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF OLD.effective_to IS NOT NULL AND NEW.effective_to IS DISTINCT FROM OLD.effective_to THEN
                    RAISE EXCEPTION 'A closed fare rule is final.' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END \$\$
        SQL);
        DB::unprepared('CREATE TRIGGER fare_rules_guard BEFORE UPDATE OR DELETE ON fare_rules FOR EACH ROW EXECUTE FUNCTION fare_rules_guard()');

        Schema::create('fare_fees', function (Blueprint $table) {
            $table->id();
            // Either the whole zone or a drawn area (an airport, a toll gate): exactly one of the two.
            $table->foreignId('zone_id')->nullable()->constrained('zones')->restrictOnDelete();
            $table->string('name', 100);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('applies_to', 16);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->default(DB::raw("(now() AT TIME ZONE 'UTC')"));

            $table->index(['zone_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE fare_fees ADD COLUMN polygon geometry(MultiPolygon, 4326)');
        DB::statement('CREATE INDEX fare_fees_polygon_gist ON fare_fees USING GIST (polygon)');
        DB::statement("ALTER TABLE fare_fees ADD CONSTRAINT fare_fees_applies_to_check CHECK (applies_to IN ('pickup', 'dropoff', 'both'))");
        DB::statement('ALTER TABLE fare_fees ADD CONSTRAINT fare_fees_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE fare_fees ADD CONSTRAINT fare_fees_period_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement('ALTER TABLE fare_fees ADD CONSTRAINT fare_fees_where_check CHECK (num_nonnulls(zone_id, polygon) = 1)');
        DB::statement('ALTER TABLE fare_fees ADD CONSTRAINT fare_fees_polygon_valid_check CHECK (polygon IS NULL OR (ST_IsValid(polygon) AND NOT ST_IsEmpty(polygon)))');

        // What the rider was shown, kept for 10 minutes so the trip is priced with the same rule and surge.
        Schema::create('fare_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('rider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            $table->integer('distance_meters');
            $table->integer('duration_seconds');
            $table->char('currency', 3);
            // A list with one entry per vehicle class: fare_rule_id, surge, range and the full FareBreakdown.
            $table->jsonb('estimates');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->default(DB::raw("(now() AT TIME ZONE 'UTC')"));
        });

        DB::statement('ALTER TABLE fare_quotes ADD COLUMN pickup_location geography(Point, 4326) NOT NULL');
        DB::statement('ALTER TABLE fare_quotes ADD COLUMN dropoff_location geography(Point, 4326) NOT NULL');
        DB::statement('ALTER TABLE fare_quotes ADD CONSTRAINT fare_quotes_measurements_check CHECK (distance_meters >= 0 AND duration_seconds >= 0)');

        // The trip's pricing snapshot points at the rule it was priced with (the column came with P2-T4).
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('fare_rule_id')->references('id')->on('fare_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['fare_rule_id']);
        });
        Schema::dropIfExists('fare_quotes');
        Schema::dropIfExists('fare_fees');
        DB::unprepared('DROP TRIGGER IF EXISTS fare_rules_guard ON fare_rules');
        Schema::dropIfExists('fare_rules');
        DB::unprepared('DROP FUNCTION IF EXISTS fare_rules_guard()');
    }

    private function vehicleClassCheck(string $table): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'{$v}'", array_column(VehicleClass::cases(), 'value')));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_vehicle_class_check CHECK (vehicle_class IN ({$list}))");
    }
};
