<?php

namespace Tests\Feature\Drivers;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DriverSensitiveDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_number_is_encrypted_with_a_blind_index_and_found_by_exact_match(): void
    {
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL-7731']);
        DriverProfile::factory()->create(['license_number' => 'AM-DL-7732']);

        $row = DB::table('driver_profiles')->where('id', $profile->id)->first();
        $this->assertStringNotContainsString('7731', $row->license_number);
        $this->assertSame('AM-DL-7731', Crypt::decryptString($row->license_number));
        $this->assertSame(hash_hmac('sha256', 'AM-DL-7731', config('app.blind_index_key')), $row->license_number_hash);

        $this->assertTrue(DriverProfile::query()->whereLicenseNumber('AM-DL-7731')->sole()->is($profile));
        $this->assertSame(0, DriverProfile::query()->whereLicenseNumber('AM-DL-773')->count());
        $this->assertSame('AM-DL-7731', $profile->fresh()->license_number);
    }

    public function test_the_same_license_number_cannot_belong_to_two_drivers(): void
    {
        DriverProfile::factory()->create(['license_number' => 'AM-DL-1000']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('driver_profiles_license_number_hash_unique');

        DriverProfile::factory()->create(['license_number' => 'AM-DL-1000']);
    }

    public function test_a_profile_without_a_license_yet_has_no_hash(): void
    {
        $first = DriverProfile::factory()->create(['license_number' => null]);
        $second = DriverProfile::factory()->create(['license_number' => null]);

        $this->assertDatabaseHas('driver_profiles', ['id' => $first->id, 'license_number' => null, 'license_number_hash' => null]);
        $this->assertDatabaseHas('driver_profiles', ['id' => $second->id, 'license_number' => null, 'license_number_hash' => null]);
    }

    public function test_account_number_is_encrypted_and_last4_is_derived(): void
    {
        $account = DriverBankAccount::factory()->create(['account_number' => 'AM12 3456 7890 4821']);

        $row = DB::table('driver_bank_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString('4821', $row->account_number);
        $this->assertSame('AM12345678904821', Crypt::decryptString($row->account_number));
        $this->assertSame('4821', $row->account_last4);
        $this->assertSame('AM12345678904821', $account->fresh()->account_number);

        $account->update(['account_number' => '0011223344']);
        $this->assertDatabaseHas('driver_bank_accounts', ['id' => $account->id, 'account_last4' => '3344']);
    }

    public function test_sensitive_columns_are_never_serialised(): void
    {
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL-5555']);
        $account = DriverBankAccount::factory()->for($profile, 'driver')->create(['account_number' => 'AM00 1111 2222 9876']);

        $profileArray = $profile->fresh()->toArray();
        $accountArray = $account->fresh()->toArray();

        $this->assertArrayNotHasKey('license_number', $profileArray);
        $this->assertArrayNotHasKey('license_number_hash', $profileArray);
        $this->assertArrayNotHasKey('account_number', $accountArray);
        $this->assertSame('9876', $accountArray['account_last4']);
        $this->assertStringNotContainsString('5555', $profile->fresh()->toJson());
        $this->assertStringNotContainsString('22229876', $account->fresh()->toJson());
    }

    public function test_license_numbers_are_stored_normalised_and_looked_up_ignoring_case_and_spaces(): void
    {
        $profile = DriverProfile::factory()->create(['license_number' => ' am-dl 42 ']);

        $row = DB::table('driver_profiles')->where('id', $profile->id)->first();
        $this->assertSame('AM-DL42', Crypt::decryptString($row->license_number));
        $this->assertSame(hash_hmac('sha256', 'AM-DL42', config('app.blind_index_key')), $row->license_number_hash);

        foreach (['AM-DL42', 'am-dl42', 'AM-DL 42', " am-dl\t42 "] as $typed) {
            $this->assertTrue(
                DriverProfile::query()->whereLicenseNumber($typed)->sole()->is($profile),
                "Lookup for [{$typed}] must find the driver.",
            );
        }
        $this->assertSame(0, DriverProfile::query()->whereLicenseNumber('AM-DL-42')->count(), 'Hyphens stay significant.');
    }

    public function test_the_same_license_in_a_different_case_or_spacing_is_a_duplicate(): void
    {
        DriverProfile::factory()->create(['license_number' => 'AM-DL-2000']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('driver_profiles_license_number_hash_unique');

        DriverProfile::factory()->create(['license_number' => ' am-dl-2000']);
    }

    public function test_phone_blind_index_is_not_normalised_by_the_license_rule(): void
    {
        $user = User::factory()->withPhone('+15550007777')->create();

        $this->assertSame(hash_hmac('sha256', '+15550007777', config('app.blind_index_key')), DB::table('users')->where('id', $user->id)->value('phone_hash'));
    }
}
