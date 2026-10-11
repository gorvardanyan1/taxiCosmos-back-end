<?php

namespace Tests\Feature\Trips;

use App\Data\GeoPoint;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TripSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run a statement the database should refuse, inside a savepoint so the test transaction survives.
     */
    private function assertRefused(callable $change, string $constraint): void
    {
        try {
            DB::transaction($change);
            $this->fail("The database accepted a row that breaks {$constraint}.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    public function test_location_columns_are_wgs84_geography_points(): void
    {
        foreach (['pickup_location', 'dropoff_location'] as $column) {
            $info = DB::selectOne(
                "SELECT type, srid FROM geography_columns WHERE f_table_name = 'trips' AND f_geography_column = ?",
                [$column],
            );

            $this->assertSame('Point', $info->type, $column);
            $this->assertSame(4326, $info->srid, $column);
        }
    }

    public function test_both_locations_have_a_gist_index(): void
    {
        foreach (['trips_pickup_location_gist' => 'pickup_location', 'trips_dropoff_location_gist' => 'dropoff_location'] as $name => $column) {
            $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'trips' AND indexname = ?", [$name]);

            $this->assertNotNull($index, "{$name} is missing");
            $this->assertStringContainsString("USING gist ({$column})", $index->indexdef);
        }
    }

    public function test_the_gist_index_serves_radius_searches(): void
    {
        Trip::factory()->count(3)->create();
        DB::statement('SET enable_seqscan = off');

        $plan = collect(DB::select(
            'EXPLAIN SELECT id FROM trips WHERE ST_DWithin(pickup_location, ST_SetSRID(ST_MakePoint(44.5035, 40.1772), 4326)::geography, 500)',
        ))->pluck('QUERY PLAN')->implode("\n");

        $this->assertStringContainsString('trips_pickup_location_gist', $plan);
    }

    public function test_a_point_round_trips_through_postgis_exactly(): void
    {
        $trip = Trip::factory()->create([
            'pickup_location' => new GeoPoint(40.177200123456, 44.503500654321),
            'dropoff_location' => new GeoPoint(-33.8688, 151.2093),
        ]);

        $fresh = Trip::findOrFail($trip->id);

        $this->assertSame(40.177200123456, $fresh->pickup_location->latitude);
        $this->assertSame(44.503500654321, $fresh->pickup_location->longitude);
        $this->assertSame(-33.8688, $fresh->dropoff_location->latitude);
        $this->assertSame(151.2093, $fresh->dropoff_location->longitude);
    }

    public function test_the_stored_value_is_what_postgis_itself_reads_as_lat_lng(): void
    {
        $trip = Trip::factory()->create(['pickup_location' => new GeoPoint(40.25, 44.5)]);

        $row = DB::selectOne('SELECT ST_Y(pickup_location::geometry) AS lat, ST_X(pickup_location::geometry) AS lng, ST_SRID(pickup_location::geometry) AS srid FROM trips WHERE id = ?', [$trip->id]);

        $this->assertSame(40.25, $row->lat);
        $this->assertSame(44.5, $row->lng);
        $this->assertSame(4326, $row->srid);
    }

    public function test_the_model_hands_back_the_same_ewkb_postgis_produces(): void
    {
        $point = new GeoPoint(40.1872, 44.5152);
        $trip = Trip::factory()->create(['dropoff_location' => $point]);

        $postgis = DB::selectOne('SELECT upper(encode(ST_AsEWKB(dropoff_location::geometry), \'hex\')) AS hex FROM trips WHERE id = ?', [$trip->id])->hex;

        $this->assertSame($postgis, $point->toEwkbHex());
    }

    public function test_a_location_must_be_a_geo_point(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Trip::factory()->create(['pickup_location' => '40.1,44.5']);
    }

    public function test_locations_are_required(): void
    {
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['pickup_location' => null])), 'pickup_location');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['dropoff_location' => null])), 'dropoff_location');
    }

    public function test_the_money_snapshot_is_integer_minor_units(): void
    {
        $trip = Trip::factory()->completed()->create();

        $columns = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'trips' AND column_name IN ('estimated_fare_amount', 'fare_amount', 'commission_amount', 'driver_earnings_amount')");
        foreach ($columns as $column) {
            $this->assertSame('bigint', $column->data_type, $column->column_name);
        }

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(150000, $fresh->fare_amount);
        $this->assertSame(30000, $fresh->commission_amount);
        $this->assertSame(120000, $fresh->driver_earnings_amount);
        $this->assertSame('AMD', $fresh->fare_currency);
    }

    public function test_the_fare_breakdown_is_jsonb_and_survives_a_round_trip(): void
    {
        $trip = Trip::factory()->completed()->create();

        $type = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'trips' AND column_name = 'fare_breakdown'")->data_type;

        $this->assertSame('jsonb', $type);
        $this->assertSame(63000, Trip::findOrFail($trip->id)->fare_breakdown['distance']);
    }

    public function test_surge_multiplier_keeps_two_decimals(): void
    {
        $trip = Trip::factory()->create(['surge_multiplier' => '1.50']);

        $this->assertSame('1.50', Trip::findOrFail($trip->id)->surge_multiplier);
        $this->assertRefused(fn () => Trip::factory()->create(['surge_multiplier' => '0.50']), 'trips_surge_multiplier_check');
    }

    public function test_a_new_trip_defaults_to_requested_and_unpaid(): void
    {
        $trip = Trip::findOrFail(Trip::factory()->create()->id);

        $this->assertSame('requested', $trip->status->value);
        $this->assertSame('unpaid', $trip->payment_status->value);
        $this->assertNull($trip->driver_id);
        $this->assertNull($trip->cancelled_by);
    }

    public function test_every_trip_gets_a_unique_readable_public_code(): void
    {
        $codes = Trip::factory()->count(25)->create()->pluck('public_code');

        $this->assertCount(25, $codes->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^TK-[2-9A-HJKMNP-Z]{8}$/', $code);
        }
    }

    public function test_a_duplicate_public_code_is_refused(): void
    {
        $existing = Trip::factory()->create();

        $this->assertRefused(fn () => Trip::factory()->create(['public_code' => $existing->public_code]), 'trips_public_code_unique');
    }

    public function test_status_and_payment_values_are_constrained(): void
    {
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['status' => 'teleporting'])), 'trips_status_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['payment_status' => 'maybe'])), 'trips_payment_status_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['payment_method' => 'barter'])), 'trips_payment_method_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['vehicle_class' => 'tank'])), 'trips_vehicle_class_check');
    }

    public function test_the_row_can_not_contradict_its_status(): void
    {
        // matched without a driver
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['status' => 'matched'])), 'trips_driver_assigned_check');
        // cancelled without who/when
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['status' => 'cancelled', 'cancelled_at' => now()])), 'trips_cancelled_by_status_check');
        // a cancelled_by on a trip that is not cancelled
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['cancelled_by' => 'rider'])), 'trips_cancelled_by_status_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['status' => 'cancelled', 'cancelled_by' => 'rider'])), 'trips_cancelled_at_status_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['cancelled_at' => now()])), 'trips_cancelled_at_status_check');
        // completed without its final money
        $driver = DriverProfile::factory()->create();
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['status' => 'completed', 'driver_id' => $driver->id, 'completed_at' => now()])), 'trips_completion_check');
    }

    public function test_commission_plus_earnings_can_not_exceed_the_fare(): void
    {
        $this->assertRefused(
            fn () => Trip::factory()->completed()->create(['commission_amount' => 100000, 'driver_earnings_amount' => 100000]),
            'trips_earnings_check',
        );
    }

    public function test_money_columns_refuse_negative_amounts(): void
    {
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['estimated_fare_amount' => -1])), 'trips_amounts_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['fare_amount' => -5])), 'trips_amounts_check');
        $this->assertRefused(fn () => DB::table('trips')->insert($this->rawRow(['distance_meters' => -1])), 'trips_measurements_check');
    }

    public function test_the_zone_rider_and_driver_can_not_be_deleted_while_trips_use_them(): void
    {
        $trip = Trip::factory()->matched()->create();

        $this->assertRefused(fn () => DB::table('zones')->where('id', $trip->zone_id)->delete(), 'trips_zone_id_foreign');
        $this->assertRefused(fn () => DB::table('users')->where('id', $trip->rider_id)->delete(), 'trips_rider_id_foreign');
        $this->assertRefused(fn () => DB::table('driver_profiles')->where('id', $trip->driver_id)->delete(), 'trips_driver_id_foreign');
    }

    public function test_a_soft_deleted_vehicle_still_shows_on_its_old_trips(): void
    {
        $trip = Trip::factory()->matched()->create();
        $vehicle = Vehicle::factory()->create(['driver_id' => $trip->driver_id]);
        $trip->forceFill(['vehicle_id' => $vehicle->id])->save();
        $vehicle->delete();

        $this->assertSame($vehicle->id, Trip::findOrFail($trip->id)->vehicle->id);
    }

    public function test_relations_resolve(): void
    {
        $trip = Trip::factory()->matched()->create();
        $fresh = Trip::findOrFail($trip->id);

        $this->assertTrue($fresh->rider->is(User::findOrFail($trip->rider_id)));
        $this->assertTrue($fresh->driver->is(DriverProfile::findOrFail($trip->driver_id)));
        $this->assertTrue($fresh->zone->is(Zone::findOrFail($trip->zone_id)));
    }

    public function test_status_pricing_and_driver_are_not_mass_assignable(): void
    {
        $trip = new Trip([
            'status' => 'completed', 'driver_id' => 5, 'fare_amount' => 1, 'commission_amount' => 1, 'public_code' => 'TK-HACKED00',
            'rider_id' => 9, 'zone_id' => 9, 'pickup_address' => 'A',
        ]);

        $this->assertSame('requested', $trip->status->value);
        $this->assertNull($trip->driver_id);
        $this->assertNull($trip->fare_amount);
        $this->assertNull($trip->commission_amount);
        $this->assertNull($trip->public_code);
        $this->assertNull($trip->rider_id);
        $this->assertNull($trip->zone_id);
        $this->assertSame('A', $trip->pickup_address);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function rawRow(array $override = []): array
    {
        $point = 'ST_SetSRID(ST_MakePoint(44.5, 40.2), 4326)::geography';

        $row = [
            'public_code' => 'TK-'.strtoupper(substr(md5((string) microtime(true).random_int(0, 99999)), 0, 8)),
            'rider_id' => User::factory()->rider()->create()->id,
            'zone_id' => Zone::factory()->create()->id,
            'status' => 'requested',
            'pickup_address' => 'A', 'dropoff_address' => 'B',
            'requested_at' => now(), 'vehicle_class' => 'economy', 'payment_method' => 'cash',
            'fare_currency' => 'AMD', 'estimated_fare_amount' => 1000,
            'pickup_location' => DB::raw($point), 'dropoff_location' => DB::raw($point),
            'created_at' => now(), 'updated_at' => now(),
        ];

        foreach ($override as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }
}
