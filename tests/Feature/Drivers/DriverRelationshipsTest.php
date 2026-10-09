<?php

namespace Tests\Feature\Drivers;

use App\Enums\DriverVerificationStatus;
use App\Enums\VehicleClass;
use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DriverRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_one_driver_profile_and_profile_belongs_to_user(): void
    {
        $user = User::factory()->driver()->create();
        $this->assertNull($user->driverProfile);

        $profile = DriverProfile::factory()->for($user)->create();

        $this->assertTrue($user->fresh()->driverProfile->is($profile));
        $this->assertTrue($profile->user->is($user));
        $this->assertSame(DriverVerificationStatus::Pending, $user->fresh()->driverProfile->verification_status);
    }

    public function test_a_rider_who_drives_keeps_one_user_row_with_a_driver_profile(): void
    {
        $user = User::factory()->rider()->create();
        $user->enableDriverMode();
        $user->driverProfile()->create(['license_number' => 'DL-RIDER-1']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_rider' => true, 'is_driver' => true]);
        $this->assertSame($user->id, DriverProfile::query()->sole()->user_id);
    }

    public function test_profile_has_many_vehicles_only_its_own(): void
    {
        $profile = DriverProfile::factory()->create();
        $mine = Vehicle::factory()->count(2)->for($profile, 'driver')->create();
        $other = Vehicle::factory()->create();

        $this->assertEqualsCanonicalizing($mine->modelKeys(), $profile->vehicles->modelKeys());
        $this->assertNotContains($other->id, $profile->vehicles->modelKeys());
        $this->assertTrue($mine->first()->driver->is($profile));
    }

    public function test_profile_has_many_bank_accounts_only_its_own(): void
    {
        $profile = DriverProfile::factory()->create();
        $mine = DriverBankAccount::factory()->count(2)->for($profile, 'driver')->create();
        $other = DriverBankAccount::factory()->create();

        $this->assertEqualsCanonicalizing($mine->modelKeys(), $profile->bankAccounts->modelKeys());
        $this->assertNotContains($other->id, $profile->bankAccounts->modelKeys());
        $this->assertTrue($mine->first()->driver->is($profile));
    }

    public function test_primary_vehicle_and_default_bank_account_relations(): void
    {
        $profile = DriverProfile::factory()->create();
        Vehicle::factory()->for($profile, 'driver')->create();
        $primary = Vehicle::factory()->primary()->for($profile, 'driver')->create(['vehicle_class' => VehicleClass::Comfort]);
        DriverBankAccount::factory()->for($profile, 'driver')->create();
        $default = DriverBankAccount::factory()->default()->for($profile, 'driver')->create();

        $fresh = $profile->fresh();
        $this->assertTrue($fresh->primaryVehicle->is($primary));
        $this->assertSame(VehicleClass::Comfort, $fresh->primaryVehicle->vehicle_class);
        $this->assertTrue($fresh->defaultBankAccount->is($default));
    }

    public function test_soft_deleted_vehicles_and_accounts_drop_out_of_the_relations_but_keep_their_rows(): void
    {
        $profile = DriverProfile::factory()->create();
        $vehicle = Vehicle::factory()->primary()->for($profile, 'driver')->create();
        $account = DriverBankAccount::factory()->default()->for($profile, 'driver')->create();

        $vehicle->delete();
        $account->delete();

        $fresh = $profile->fresh();
        $this->assertCount(0, $fresh->vehicles);
        $this->assertNull($fresh->primaryVehicle);
        $this->assertCount(0, $fresh->bankAccounts);
        $this->assertNull($fresh->defaultBankAccount);
        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
        $this->assertSoftDeleted('driver_bank_accounts', ['id' => $account->id]);
    }

    public function test_approved_by_points_to_the_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = DriverProfile::factory()->approved($admin)->create();

        $this->assertTrue($profile->fresh()->approvedBy->is($admin));
    }

    public function test_eager_loading_a_driver_list_uses_a_fixed_number_of_queries(): void
    {
        DriverProfile::factory()->count(5)
            ->has(Vehicle::factory()->primary(), 'vehicles')
            ->has(DriverBankAccount::factory()->default(), 'bankAccounts')
            ->create();

        DB::enableQueryLog();
        $drivers = DriverProfile::with(['user', 'primaryVehicle', 'defaultBankAccount'])->get();
        $drivers->each(fn (DriverProfile $d) => [$d->user->id, $d->primaryVehicle->plate_number, $d->defaultBankAccount->account_last4]);

        $this->assertCount(5, $drivers);
        $this->assertCount(4, DB::getQueryLog());
    }
}
