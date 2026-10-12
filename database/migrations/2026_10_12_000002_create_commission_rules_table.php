<?php

use App\Enums\CommissionType;
use App\Enums\VehicleClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Everything on a commission rule except the moment it stops being current. */
    private const FROZEN_COLUMNS = [
        'zone_id', 'vehicle_class', 'type', 'rate_bps', 'fixed_amount', 'currency', 'min_commission', 'max_commission',
        'applies_to_surge', 'effective_from', 'created_by', 'created_at',
    ];

    public function up(): void
    {
        // The exclusion constraint below compares "same zone and class" (=) with "overlapping period" (&&); fare_rules enabled it too.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            // Null = every vehicle class of the zone; a rule for one class beats it.
            $table->string('vehicle_class', 32)->nullable();
            $table->string('type', 32);
            // Basis points: 2000 = 20 %. Money columns are integer minor units of `currency` (the zone's currency).
            $table->unsignedSmallInteger('rate_bps')->default(0);
            $table->bigInteger('fixed_amount')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('min_commission')->default(0);
            $table->bigInteger('max_commission')->nullable();
            $table->boolean('applies_to_surge')->default(true);
            // A rule applies from effective_from (inclusive) to effective_to (exclusive); null = still current.
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->default(DB::raw("(now() AT TIME ZONE 'UTC')"));

            $table->index(['zone_id', 'vehicle_class', 'effective_from']);
        });

        $classes = implode(', ', array_map(fn (string $v) => "'{$v}'", array_column(VehicleClass::cases(), 'value')));
        $types = implode(', ', array_map(fn (string $v) => "'{$v}'", array_column(CommissionType::cases(), 'value')));

        $constraints = [
            'vehicle_class_check' => "vehicle_class IS NULL OR vehicle_class IN ({$classes})",
            'type_check' => "type IN ({$types})",
            'rate_check' => 'rate_bps BETWEEN 0 AND 10000',
            'amounts_check' => 'fixed_amount >= 0 AND min_commission >= 0 AND (max_commission IS NULL OR max_commission >= min_commission)',
            // The columns a type does not use must be empty, so a stored rule can only mean one thing.
            'type_terms_check' => "(type = 'percentage' AND fixed_amount = 0) OR (type = 'fixed' AND rate_bps = 0) OR type = 'percentage_plus_fixed'",
            'period_check' => 'effective_to IS NULL OR effective_to > effective_from',
        ];

        foreach ($constraints as $name => $expression) {
            DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_{$name} CHECK ({$expression})");
        }

        // One zone and class (or "all classes") has at most one rule at any instant.
        DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_no_overlap EXCLUDE USING gist (zone_id WITH =, (COALESCE(vehicle_class, 'all')) WITH =, tsrange(effective_from, effective_to) WITH &&)");

        // Rules are versioned, never edited: the only change a row allows is closing it once, and no row is ever deleted.
        $same = implode(' AND ', array_map(fn (string $column) => "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}", self::FROZEN_COLUMNS));
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION commission_rules_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Commission rules are history and cannot be deleted.' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NOT ({$same}) THEN
                    RAISE EXCEPTION 'Commission rules are never edited: add a new version instead.' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF OLD.effective_to IS NOT NULL AND NEW.effective_to IS DISTINCT FROM OLD.effective_to THEN
                    RAISE EXCEPTION 'A closed commission rule is final.' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END \$\$
        SQL);
        DB::unprepared('CREATE TRIGGER commission_rules_guard BEFORE UPDATE OR DELETE ON commission_rules FOR EACH ROW EXECUTE FUNCTION commission_rules_guard()');

        // The trip's pricing snapshot points at the rule its commission was taken with (the column came with P2-T4).
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('commission_rule_id')->references('id')->on('commission_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['commission_rule_id']);
        });
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rules_guard ON commission_rules');
        Schema::dropIfExists('commission_rules');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_rules_guard()');
    }
};
