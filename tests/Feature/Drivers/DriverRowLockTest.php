<?php

namespace Tests\Feature\Drivers;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves markAsPrimary / markAsDefault take the driver row lock, so two concurrent switches
 * for one driver queue up instead of one failing on the partial unique index.
 * Uses committed data and a second real connection (RefreshDatabase's wrapping
 * transaction would hide the rows from it).
 */
class DriverRowLockTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::OTHER => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::purge(self::OTHER);
        DB::statement('RESET lock_timeout');
        // DatabaseTruncation only truncates before a test; clear the committed rows so they
        // never leak into the RefreshDatabase tests that run after this class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_mark_as_primary_waits_for_the_driver_row_lock(): void
    {
        $driver = DriverProfile::factory()->create();
        Vehicle::factory()->primary()->for($driver, 'driver')->create();
        $second = Vehicle::factory()->for($driver, 'driver')->create();

        $this->holdDriverLock($driver);

        $this->assertBlockedByLock(fn () => $second->markAsPrimary());
        $this->assertFalse($second->fresh()->is_primary, 'Nothing may change while the lock is held.');
    }

    public function test_mark_as_default_waits_for_the_driver_row_lock(): void
    {
        $driver = DriverProfile::factory()->create();
        DriverBankAccount::factory()->default()->for($driver, 'driver')->create();
        $second = DriverBankAccount::factory()->for($driver, 'driver')->create();

        $this->holdDriverLock($driver);

        $this->assertBlockedByLock(fn () => $second->markAsDefault());
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_the_switch_goes_through_once_the_lock_is_released(): void
    {
        $driver = DriverProfile::factory()->create();
        $first = Vehicle::factory()->primary()->for($driver, 'driver')->create();
        $second = Vehicle::factory()->for($driver, 'driver')->create();

        $this->holdDriverLock($driver);
        DB::connection(self::OTHER)->rollBack();

        $second->markAsPrimary();

        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($first->fresh()->is_primary);
        DB::connection(self::OTHER)->beginTransaction(); // balance tearDown's rollBack
    }

    private function holdDriverLock(DriverProfile $driver): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $other->table('driver_profiles')->where('id', $driver->id)->lockForUpdate()->first();
    }

    private function assertBlockedByLock(callable $action): void
    {
        DB::statement("SET lock_timeout = '300ms'");

        try {
            $action();
            $this->fail('The switch must wait for the driver row lock held by another transaction.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        }
    }
}
