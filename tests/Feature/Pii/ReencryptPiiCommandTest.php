<?php

namespace Tests\Feature\Pii;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReencryptPiiCommandTest extends TestCase
{
    use RefreshDatabase;

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.blind_index_key'));
    }

    /** Make a users row look like one written before E.164 normalisation existed. */
    private function legacyPhone(User $user, string $typed): void
    {
        DB::table('users')->where('id', $user->id)->update(['phone' => Crypt::encryptString($typed), 'phone_hash' => $this->hash($typed)]);
    }

    public function test_it_re_encrypts_every_registered_column_keeping_values_hashes_and_timestamps(): void
    {
        $this->travelTo(now()->subDay());
        $user = User::factory()->create(['phone' => '+37491440221']);
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        $account = DriverBankAccount::factory()->for($profile, 'driver')->create(['account_number' => 'AM12 3456 7890 4821']);
        $this->travelBack();
        $before = [DB::table('users')->find($user->id), DB::table('driver_profiles')->find($profile->id), DB::table('driver_bank_accounts')->find($account->id)];

        $this->assertSame(0, Artisan::call('pii:reencrypt'));
        $output = Artisan::output();

        $after = [DB::table('users')->find($user->id), DB::table('driver_profiles')->find($profile->id), DB::table('driver_bank_accounts')->find($account->id)];
        $this->assertNotSame($before[0]->phone, $after[0]->phone);
        $this->assertNotSame($before[1]->license_number, $after[1]->license_number);
        $this->assertNotSame($before[2]->account_number, $after[2]->account_number);
        $this->assertSame('+37491440221', $user->fresh()->phone);
        $this->assertSame('AM-DL42', $profile->fresh()->license_number);
        $this->assertSame('AM1234567890'.'4821', $account->fresh()->account_number);
        $this->assertSame($before[0]->phone_hash, $after[0]->phone_hash);
        $this->assertSame($before[1]->license_number_hash, $after[1]->license_number_hash);
        $this->assertSame('4821', $after[2]->account_last4, 'The masked-display fragment is untouched.');
        $this->assertSame($before[0]->updated_at, $after[0]->updated_at, 'Re-encrypting is not a user change: updated_at stays.');
        $this->assertStringContainsString('users.phone', $output);
        $this->assertStringNotContainsString('37491440221', $output, 'Values are never printed.');
        $this->assertStringNotContainsString('AM-DL42', $output);
    }

    public function test_a_dry_run_checks_everything_and_changes_nothing(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        $before = [DB::table('users')->find($user->id), DB::table('driver_profiles')->find($profile->id)];

        $this->assertSame(0, Artisan::call('pii:reencrypt', ['--dry-run' => true]));

        $this->assertEquals($before, [DB::table('users')->find($user->id), DB::table('driver_profiles')->find($profile->id)]);
        $this->assertStringContainsString('Dry run: all values can be read', Artisan::output());
    }

    public function test_rows_without_a_value_and_soft_deleted_rows_are_handled(): void
    {
        $withoutPhone = User::factory()->create();
        $deleted = User::factory()->create(['phone' => '+37491440221']);
        $deleted->delete();
        $before = DB::table('users')->find($deleted->id)->phone;

        $this->assertSame(0, Artisan::call('pii:reencrypt'));

        $this->assertNull(DB::table('users')->find($withoutPhone->id)->phone);
        $this->assertNotSame($before, DB::table('users')->find($deleted->id)->phone, 'Soft-deleted rows hold personal data too.');
        $this->assertSame('+37491440221', User::withTrashed()->find($deleted->id)->phone);
    }

    public function test_it_upgrades_rows_written_before_e164_normalisation_so_lookups_work(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);
        $this->legacyPhone($user, '091 440 221');
        $this->assertSame(0, User::query()->wherePhone('091 440 221')->count(), 'Legacy hash does not match a normalised lookup.');

        $this->assertSame(0, Artisan::call('pii:reencrypt'));

        $raw = DB::table('users')->find($user->id);
        $this->assertSame('+37491440221', Crypt::decryptString($raw->phone));
        $this->assertSame($this->hash('+37491440221'), $raw->phone_hash);
        $this->assertTrue(User::query()->wherePhone('091 440 221')->sole()->is($user));
    }

    public function test_it_rebuilds_license_hashes_with_the_current_normalisation(): void
    {
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        DB::table('driver_profiles')->where('id', $profile->id)->update(['license_number' => Crypt::encryptString('am-dl 42'), 'license_number_hash' => $this->hash('am-dl 42')]);

        Artisan::call('pii:reencrypt');

        $this->assertSame('AM-DL42', $profile->fresh()->license_number);
        $this->assertTrue(DriverProfile::query()->whereLicenseNumber('Am-Dl42')->sole()->is($profile));
    }

    public function test_after_changing_the_blind_index_key_the_command_rebuilds_every_hash(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);

        config(['app.blind_index_key' => str_repeat('n', 40)]);
        $this->app->forgetInstance(BlindIndex::class);
        $this->assertSame(0, User::query()->wherePhone('+37491440221')->count(), 'Until the command runs, lookups find nothing.');
        $this->assertSame(0, DriverProfile::query()->whereLicenseNumber('AM-DL42')->count());

        $this->assertSame(0, Artisan::call('pii:reencrypt'));

        $this->assertTrue(User::query()->wherePhone('+37491440221')->sole()->is($user));
        $this->assertTrue(DriverProfile::query()->whereLicenseNumber('AM-DL42')->sole()->is($profile));
        $this->assertSame(hash_hmac('sha256', '+37491440221', str_repeat('n', 40)), DB::table('users')->find($user->id)->phone_hash);
    }

    public function test_an_unreadable_or_invalid_value_fails_the_run_but_the_other_rows_are_still_processed(): void
    {
        $good = User::factory()->create(['phone' => '+37491440221']);
        $corrupt = User::factory()->create(['phone' => '+37493111222']);
        $notAPhone = User::factory()->create(['phone' => '+37494222333']);
        DB::table('users')->where('id', $corrupt->id)->update(['phone' => 'corrupted-not-a-ciphertext']);
        DB::table('users')->where('id', $notAPhone->id)->update(['phone' => Crypt::encryptString('call me maybe')]);
        $goodBefore = DB::table('users')->find($good->id)->phone;
        $badBefore = DB::table('users')->whereIn('id', [$corrupt->id, $notAPhone->id])->orderBy('id')->pluck('phone')->all();

        $exit = Artisan::call('pii:reencrypt');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertNotSame($goodBefore, DB::table('users')->find($good->id)->phone, 'The good row was still re-encrypted.');
        $this->assertSame($badBefore, DB::table('users')->whereIn('id', [$corrupt->id, $notAPhone->id])->orderBy('id')->pluck('phone')->all(), 'Failed rows are left exactly as they were.');
        $this->assertStringContainsString("users.phone #{$corrupt->id}", $output);
        $this->assertStringContainsString("users.phone #{$notAPhone->id}", $output);
        $this->assertStringContainsString('2 value(s) could not be processed', $output);
        $this->assertStringNotContainsString('call me maybe', $output);
    }

    public function test_two_legacy_numbers_that_normalise_to_the_same_value_are_reported_not_silently_merged(): void
    {
        $a = User::factory()->create(['phone' => '+37491440221']);
        $b = User::factory()->create(['phone' => '+37493111222']);
        $this->legacyPhone($a, '091 440 221');
        $this->legacyPhone($b, '+374 91 440 221');

        $exit = Artisan::call('pii:reencrypt');

        $this->assertSame(1, $exit);
        $this->assertSame(1, DB::table('users')->where('phone_hash', $this->hash('+37491440221'))->count(), 'Exactly one row owns the number; the duplicate is reported.');
        $this->assertStringContainsString('could not be processed', Artisan::output());
    }

    public function test_running_it_twice_is_safe(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);

        Artisan::call('pii:reencrypt');
        $afterFirst = DB::table('users')->find($user->id);
        $this->assertSame(0, Artisan::call('pii:reencrypt'));

        $this->assertSame('+37491440221', $user->fresh()->phone);
        $this->assertSame($afterFirst->phone_hash, DB::table('users')->find($user->id)->phone_hash);
    }

    public function test_it_fires_no_model_or_realtime_events(): void
    {
        Event::fake();
        User::factory()->create(['phone' => '+37491440221']);
        DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        Event::fake(); // forget the factory events

        Artisan::call('pii:reencrypt');

        Event::assertNothingDispatched();
    }
}
