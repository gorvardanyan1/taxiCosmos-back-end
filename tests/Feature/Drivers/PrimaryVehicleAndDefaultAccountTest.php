<?php

namespace Tests\Feature\Drivers;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrimaryVehicleAndDefaultAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_primary_vehicle_for_the_same_driver_is_rejected_by_the_database(): void
    {
        $driver = DriverProfile::factory()->create();
        Vehicle::factory()->primary()->for($driver, 'driver')->create();

        try {
            DB::transaction(fn () => Vehicle::factory()->primary()->for($driver, 'driver')->create());
            $this->fail('Two primary vehicles must violate vehicles_one_primary_per_driver.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('vehicles_one_primary_per_driver', $e->getMessage());
        }

        $this->assertSame(1, $driver->vehicles()->where('is_primary', true)->count());
    }

    public function test_flipping_an_existing_vehicle_to_primary_via_raw_sql_is_also_rejected(): void
    {
        $driver = DriverProfile::factory()->create();
        Vehicle::factory()->primary()->for($driver, 'driver')->create();
        $second = Vehicle::factory()->for($driver, 'driver')->create();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('vehicles')->where('id', $second->id)->update(['is_primary' => true]);
    }

    public function test_many_non_primary_vehicles_and_other_drivers_primaries_are_allowed(): void
    {
        $driver = DriverProfile::factory()->create();
        Vehicle::factory()->count(3)->for($driver, 'driver')->create();
        Vehicle::factory()->primary()->for($driver, 'driver')->create();
        Vehicle::factory()->primary()->create();
        Vehicle::factory()->primary()->create();

        $this->assertSame(4, $driver->vehicles()->count());
        $this->assertSame(3, Vehicle::query()->where('is_primary', true)->count());
    }

    public function test_a_soft_deleted_primary_vehicle_does_not_block_a_new_primary(): void
    {
        $driver = DriverProfile::factory()->create();
        $old = Vehicle::factory()->primary()->for($driver, 'driver')->create();
        $old->delete();

        $new = Vehicle::factory()->primary()->for($driver, 'driver')->create();

        $this->assertTrue($driver->fresh()->primaryVehicle->is($new));
        $this->assertDatabaseHas('vehicles', ['id' => $old->id, 'is_primary' => true]);
    }

    public function test_restoring_a_soft_deleted_primary_while_another_is_primary_is_rejected(): void
    {
        $driver = DriverProfile::factory()->create();
        $old = Vehicle::factory()->primary()->for($driver, 'driver')->create();
        $old->delete();
        Vehicle::factory()->primary()->for($driver, 'driver')->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $old->restore();
    }

    public function test_mark_as_primary_moves_the_flag_and_leaves_exactly_one_primary(): void
    {
        $driver = DriverProfile::factory()->create();
        $first = Vehicle::factory()->primary()->for($driver, 'driver')->create();
        $second = Vehicle::factory()->for($driver, 'driver')->create();
        $othersPrimary = Vehicle::factory()->primary()->create();

        $second->markAsPrimary();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertTrue($othersPrimary->fresh()->is_primary, 'Another driver\'s primary must be untouched.');
        $this->assertSame(1, $driver->vehicles()->where('is_primary', true)->count());

        // Repeating it is a no-op.
        $second->fresh()->markAsPrimary();
        $this->assertTrue($driver->fresh()->primaryVehicle->is($second));
    }

    public function test_a_second_default_bank_account_for_the_same_driver_is_rejected_by_the_database(): void
    {
        $driver = DriverProfile::factory()->create();
        DriverBankAccount::factory()->default()->for($driver, 'driver')->create();

        try {
            DB::transaction(fn () => DriverBankAccount::factory()->default()->for($driver, 'driver')->create());
            $this->fail('Two default accounts must violate driver_bank_accounts_one_default_per_driver.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('driver_bank_accounts_one_default_per_driver', $e->getMessage());
        }

        $this->assertSame(1, $driver->bankAccounts()->where('is_default', true)->count());
    }

    public function test_many_non_default_accounts_and_other_drivers_defaults_are_allowed(): void
    {
        $driver = DriverProfile::factory()->create();
        DriverBankAccount::factory()->count(2)->for($driver, 'driver')->create();
        DriverBankAccount::factory()->default()->for($driver, 'driver')->create();
        DriverBankAccount::factory()->default()->create();

        $this->assertSame(3, $driver->bankAccounts()->count());
        $this->assertSame(2, DriverBankAccount::query()->where('is_default', true)->count());
    }

    public function test_a_soft_deleted_default_account_does_not_block_a_new_default(): void
    {
        $driver = DriverProfile::factory()->create();
        DriverBankAccount::factory()->default()->for($driver, 'driver')->create()->delete();

        $new = DriverBankAccount::factory()->default()->for($driver, 'driver')->create();

        $this->assertTrue($driver->fresh()->defaultBankAccount->is($new));
    }

    public function test_mark_as_default_moves_the_flag_and_leaves_exactly_one_default(): void
    {
        $driver = DriverProfile::factory()->create();
        $first = DriverBankAccount::factory()->default()->for($driver, 'driver')->create();
        $second = DriverBankAccount::factory()->for($driver, 'driver')->create();
        $othersDefault = DriverBankAccount::factory()->default()->create();

        $second->markAsDefault();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($othersDefault->fresh()->is_default);
        $this->assertSame(1, $driver->bankAccounts()->where('is_default', true)->count());
    }
}
