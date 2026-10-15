<?php

namespace Tests\Feature\DriverOnboarding;

use App\Enums\DriverDocumentType;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Drivers\DriverOnboardingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves every onboarding change takes the driver's row lock (the user's row for applying), so two simultaneous requests queue up
 * and the second sees what the first left: two taps on "add vehicle" cannot both take the last slot, two uploads of one paper end
 * with one. Uses committed data and a second real connection, like SavedPlaceRowLockTest.
 */
class DriverOnboardingRowLockTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::OTHER => config('database.connections.pgsql')]);
        Storage::fake(config('taxikosmos.documents.disk'));
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

    /** KEY SHARE conflicts only with the service's FOR UPDATE: not with a plain UPDATE and not with a foreign-key check. */
    private function hold(string $table, int $id): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $other->select("select id from {$table} where id = ? for key share", [$id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function changes(): array
    {
        return [
            'update the profile' => ['profile'], 'add a vehicle' => ['add_vehicle'], 'change a vehicle' => ['update_vehicle'],
            'remove a vehicle' => ['remove_vehicle'], 'add a bank account' => ['add_account'], 'remove a bank account' => ['remove_account'], 'upload a document' => ['upload'],
        ];
    }

    private function change(string $change, User $user, DriverProfile $profile, Vehicle $vehicle, DriverBankAccount $account): void
    {
        $service = app(DriverOnboardingService::class);
        $pdf = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        match ($change) {
            'profile' => $service->updateProfile($user, $profile, ['license_number' => 'DL-9999999', 'license_expiry' => now()->addYear()->toDateString(), 'home_zone_id' => null]),
            'add_vehicle' => $service->addVehicle($user, $profile, ['make' => 'Kia', 'model' => 'Rio', 'year' => 2021, 'color' => 'Red', 'plate_number' => 'LOCK1', 'vehicle_class' => 'economy']),
            'update_vehicle' => $service->updateVehicle($user, $profile, $vehicle, ['color' => 'Blue']),
            'remove_vehicle' => $service->removeVehicle($user, $profile, $vehicle),
            'add_account' => $service->addBankAccount($user, $profile, ['account_holder' => 'A B', 'bank_name' => 'Bank', 'account_number' => 'AM00555555555555', 'swift' => null]),
            'remove_account' => $service->removeBankAccount($user, $profile, $account),
            'upload' => $service->uploadDocument($user, $profile, DriverDocumentType::License, new UploadedFile($pdf, 'a.pdf', null, null, true), null, null, null),
        };
    }

    #[DataProvider('changes')]
    public function test_a_change_waits_for_the_drivers_row_lock_and_saves_nothing_while_it_is_held(string $change): void
    {
        $user = User::factory()->driver()->withPhone('+37491739001')->create();
        $profile = DriverProfile::factory()->create(['user_id' => $user->id, 'license_number' => 'DL-1010101', 'license_expiry' => now()->addYears(2)->toDateString()]);
        $vehicle = Vehicle::factory()->create(['driver_id' => $profile->id, 'plate_number' => 'HELD1', 'color' => 'White']);
        $account = DriverBankAccount::factory()->create(['driver_id' => $profile->id]);
        $this->hold('driver_profiles', $profile->id);
        DB::statement("SET lock_timeout = '300ms'");

        try {
            $this->change($change, $user, $profile, $vehicle, $account);
            $this->fail("To {$change} must wait for the driver row lock held by another transaction.");
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        }

        $this->assertSame([1, 1, 0], [Vehicle::query()->count(), DriverBankAccount::query()->count(), DriverDocument::query()->count()]);
        $this->assertSame('White', $vehicle->fresh()->color);
        $this->assertNull($vehicle->fresh()->deleted_at);
        $this->assertNull($account->fresh()->deleted_at);
        $this->assertSame('DL-1010101', $profile->fresh()->license_number);
    }

    public function test_applying_waits_for_the_users_row_lock(): void
    {
        $user = User::factory()->driver()->withPhone('+37491739002')->create();
        $this->hold('users', $user->id);
        DB::statement("SET lock_timeout = '300ms'");

        try {
            app(DriverOnboardingService::class)->apply($user);
            $this->fail('Applying must wait for the user row lock held by another transaction.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        }

        $this->assertSame(0, DriverProfile::query()->count());
    }

    public function test_the_change_is_applied_once_the_lock_is_released(): void
    {
        $user = User::factory()->driver()->withPhone('+37491739003')->create();
        $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
        $this->hold('driver_profiles', $profile->id);
        DB::connection(self::OTHER)->rollBack();

        $this->change('add_vehicle', $user, $profile, new Vehicle, new DriverBankAccount);

        $this->assertSame(1, Vehicle::query()->where('driver_id', $profile->id)->count());
        DB::connection(self::OTHER)->beginTransaction(); // balance tearDown's rollBack
    }
}
