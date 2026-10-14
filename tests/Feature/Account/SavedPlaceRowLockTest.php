<?php

namespace Tests\Feature\Account;

use App\Data\GeoPoint;
use App\Enums\SavedPlaceLabel;
use App\Models\SavedPlace;
use App\Models\User;
use App\Services\Account\SavedPlaceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves adding a saved place takes the user's row lock, so two requests sent together queue up and cannot both take the
 * last of the 20 slots. Uses committed data and a second real connection (RefreshDatabase's wrapping transaction would hide
 * the rows from it), like DriverRowLockTest.
 */
class SavedPlaceRowLockTest extends TestCase
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
        // Clear the committed rows so they never leak into the RefreshDatabase tests that run after this class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function create(User $user): SavedPlace
    {
        return app(SavedPlaceService::class)->create($user, SavedPlaceLabel::Other, 'Place', 'Address', new GeoPoint(40.18, 44.51));
    }

    private function holdUserLock(User $user): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        // NO KEY UPDATE conflicts with the service's FOR UPDATE but not with the foreign-key check of the insert itself (KEY SHARE),
        // so only the service's own lock can make the insert wait.
        $other->select('select id from users where id = ? for no key update', [$user->id]);
    }

    public function test_adding_a_place_waits_for_the_user_row_lock(): void
    {
        $user = User::factory()->rider()->create();
        $this->holdUserLock($user);
        DB::statement("SET lock_timeout = '300ms'");

        try {
            $this->create($user);
            $this->fail('Adding a place must wait for the user row lock held by another transaction.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        }

        $this->assertSame(0, SavedPlace::query()->count(), 'Nothing may be added while the lock is held.');
    }

    public function test_the_place_is_added_once_the_lock_is_released(): void
    {
        $user = User::factory()->rider()->create();
        $this->holdUserLock($user);
        DB::connection(self::OTHER)->rollBack();

        $this->create($user);

        $this->assertSame(1, SavedPlace::query()->where('user_id', $user->id)->count());
        DB::connection(self::OTHER)->beginTransaction(); // balance tearDown's rollBack
    }
}
