<?php

namespace Tests\Feature\Drivers;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriverSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function foreignKeys(): array
    {
        // table, column, referenced table, ON DELETE action (pg confdeltype: r = restrict, n = set null)
        return [
            'profile → user' => ['driver_profiles', 'user_id', 'users', 'r'],
            'profile → approver' => ['driver_profiles', 'approved_by', 'users', 'n'],
            'vehicle → profile' => ['vehicles', 'driver_id', 'driver_profiles', 'r'],
            'bank account → profile' => ['driver_bank_accounts', 'driver_id', 'driver_profiles', 'r'],
        ];
    }

    #[DataProvider('foreignKeys')]
    public function test_foreign_keys_exist_with_the_right_delete_rule(string $table, string $column, string $references, string $onDelete): void
    {
        $fk = DB::selectOne(<<<'SQL'
            SELECT ref.relname AS references, c.confdeltype AS on_delete
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_class ref ON ref.oid = c.confrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND t.relname = ? AND a.attname = ?
            SQL, [$table, $column]);

        $this->assertNotNull($fk, "{$table}.{$column} has no foreign key.");
        $this->assertSame($references, $fk->references);
        $this->assertSame($onDelete, $fk->on_delete);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function indexes(): array
    {
        // table, a fragment of the index definition that must exist
        return [
            'one profile per user' => ['driver_profiles', 'UNIQUE INDEX driver_profiles_user_id_unique ON public.driver_profiles USING btree (user_id)'],
            'license blind index' => ['driver_profiles', 'UNIQUE INDEX driver_profiles_license_number_hash_unique ON public.driver_profiles USING btree (license_number_hash)'],
            'verification filter' => ['driver_profiles', '(verification_status)'],
            'availability filter' => ['driver_profiles', '(availability)'],
            'zone filter' => ['driver_profiles', '(home_zone_id)'],
            'license expiry monitoring' => ['driver_profiles', '(license_expiry)'],
            'vehicles by driver' => ['vehicles', '(driver_id, status)'],
            'plate search' => ['vehicles', '(plate_number)'],
            'class filter' => ['vehicles', '(vehicle_class)'],
            'one primary vehicle' => ['vehicles', 'UNIQUE INDEX vehicles_one_primary_per_driver ON public.vehicles USING btree (driver_id) WHERE (is_primary AND (deleted_at IS NULL))'],
            'bank accounts by driver' => ['driver_bank_accounts', '(driver_id)'],
            'one default account' => ['driver_bank_accounts', 'UNIQUE INDEX driver_bank_accounts_one_default_per_driver ON public.driver_bank_accounts USING btree (driver_id) WHERE (is_default AND (deleted_at IS NULL))'],
        ];
    }

    #[DataProvider('indexes')]
    public function test_indexes_exist(string $table, string $fragment): void
    {
        $definitions = DB::table('pg_indexes')->where('tablename', $table)->pluck('indexdef')->all();

        $this->assertNotEmpty(
            array_filter($definitions, fn (string $def) => str_contains($def, $fragment)),
            "No index on {$table} matching [{$fragment}]. Found:\n".implode("\n", $definitions),
        );
    }

    public function test_a_user_can_have_only_one_driver_profile(): void
    {
        $profile = DriverProfile::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        DriverProfile::factory()->create(['user_id' => $profile->user_id]);
    }

    public function test_a_user_with_a_driver_profile_cannot_be_hard_deleted(): void
    {
        $profile = DriverProfile::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('driver_profiles_user_id_foreign');

        $profile->user->forceDelete();
    }

    public function test_a_driver_with_vehicles_or_bank_accounts_cannot_be_hard_deleted(): void
    {
        $vehicle = Vehicle::factory()->create();
        $account = DriverBankAccount::factory()->create();

        foreach ([[$vehicle->driver, 'vehicles_driver_id_foreign'], [$account->driver, 'driver_bank_accounts_driver_id_foreign']] as [$driver, $constraint]) {
            try {
                DB::transaction(fn () => $driver->delete());
                $this->fail("Deleting a driver must be blocked by {$constraint}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }

        $this->assertDatabaseCount('driver_profiles', 2);
    }

    public function test_deleting_the_approver_keeps_the_profile_and_clears_approved_by(): void
    {
        $approver = User::factory()->create(['is_admin' => true]);
        $profile = DriverProfile::factory()->approved($approver)->create();

        $approver->forceDelete();

        $fresh = $profile->fresh();
        $this->assertNull($fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('approved', $fresh->verification_status->value);
    }

    public function test_a_vehicle_must_reference_an_existing_driver(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('vehicles_driver_id_foreign');

        Vehicle::factory()->create(['driver_id' => 999_999]);
    }

    /**
     * @return array<string, array{string, string, mixed, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'verification status' => ['driver_profiles', 'verification_status', 'verified', 'driver_profiles_verification_status_check'],
            'availability' => ['driver_profiles', 'availability', 'busy', 'driver_profiles_availability_check'],
            'driver rating above 5' => ['driver_profiles', 'rating_avg', 5.01, 'driver_profiles_rating_avg_check'],
            'driver rating below 1' => ['driver_profiles', 'rating_avg', 0.99, 'driver_profiles_rating_avg_check'],
            'vehicle class' => ['vehicles', 'vehicle_class', 'luxury', 'vehicles_vehicle_class_check'],
            'vehicle status' => ['vehicles', 'status', 'approved', 'vehicles_status_check'],
            'vehicle year' => ['vehicles', 'year', 1899, 'vehicles_year_check'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_the_database_rejects_values_outside_the_allowed_set(string $table, string $column, mixed $value, string $constraint): void
    {
        $vehicle = Vehicle::factory()->create();
        $id = $table === 'vehicles' ? $vehicle->id : $vehicle->driver_id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($constraint);

        DB::table($table)->where('id', $id)->update([$column => $value]);
    }

    public function test_boundary_values_are_accepted(): void
    {
        $vehicle = Vehicle::factory()->create();

        DB::table('driver_profiles')->where('id', $vehicle->driver_id)->update(['rating_avg' => 5, 'availability' => 'on_trip', 'verification_status' => 'expired']);
        DB::table('vehicles')->where('id', $vehicle->id)->update(['year' => 2100, 'vehicle_class' => 'business', 'status' => 'inactive']);

        $driver = $vehicle->driver->fresh();
        $this->assertSame('5.00', $driver->rating_avg);
        $this->assertSame('on_trip', $driver->availability->value);
        $this->assertSame('expired', $driver->verification_status->value);
        $this->assertSame(2100, $vehicle->fresh()->year);
    }

    public function test_new_rows_get_safe_defaults(): void
    {
        $user = User::factory()->driver()->create();
        $profile = $user->driverProfile()->create(['license_number' => 'DL-1']);
        $vehicle = $profile->vehicles()->create([
            'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022,
            'plate_number' => '35 XX 482', 'color' => 'Black', 'vehicle_class' => 'comfort',
        ]);
        $account = $profile->bankAccounts()->create([
            'account_holder' => 'Aram Petrosyan', 'account_number' => 'AM12 3456 7890 4821', 'bank_name' => 'Ameriabank',
        ]);

        $this->assertDatabaseHas('driver_profiles', [
            'id' => $profile->id, 'verification_status' => 'pending', 'availability' => 'offline',
            'rating_count' => 0, 'rating_avg' => null, 'approved_by' => null,
        ]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'pending_review', 'is_primary' => false]);
        $this->assertDatabaseHas('driver_bank_accounts', ['id' => $account->id, 'is_default' => false, 'verified_at' => null]);
    }

    public function test_review_and_flag_columns_are_not_mass_assignable(): void
    {
        $profile = DriverProfile::factory()->create();

        $profile->update(['verification_status' => 'approved', 'availability' => 'online', 'rating_avg' => 5, 'approved_by' => $profile->user_id]);
        $vehicle = $profile->vehicles()->create([
            'make' => 'Kia', 'model' => 'Optima', 'year' => 2020, 'plate_number' => '01 AA 001',
            'color' => 'White', 'vehicle_class' => 'economy', 'status' => 'active', 'is_primary' => true,
        ]);
        $account = $profile->bankAccounts()->create([
            'account_holder' => 'X', 'account_number' => '1234567890', 'bank_name' => 'Y',
            'is_default' => true, 'verified_at' => now(),
        ]);

        $this->assertDatabaseHas('driver_profiles', ['id' => $profile->id, 'verification_status' => 'pending', 'availability' => 'offline', 'rating_avg' => null, 'approved_by' => null]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'pending_review', 'is_primary' => false]);
        $this->assertDatabaseHas('driver_bank_accounts', ['id' => $account->id, 'is_default' => false, 'verified_at' => null]);
    }
}
