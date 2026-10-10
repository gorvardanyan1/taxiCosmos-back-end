<?php

namespace Tests\Feature\Pii;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** APP_KEY rotation without data loss: APP_PREVIOUS_KEYS keeps data readable, pii:reencrypt retires the old key. */
class ApplicationKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private string $oldKey;

    private string $newKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldKey = (string) config('app.key');
        $this->newKey = 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @param  list<string>  $previous
     */
    private function useKeys(string $current, array $previous = []): void
    {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function encrypterFor(string $key): Encrypter
    {
        return new Encrypter(base64_decode(substr($key, 7)), config('app.cipher'));
    }

    /**
     * @return array{user: User, profile: DriverProfile, account: DriverBankAccount}
     */
    private function seedUnderOldKey(): array
    {
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        $account = DriverBankAccount::factory()->for($profile, 'driver')->create(['account_number' => 'AM12 3456 7890 4821']);
        $user = User::factory()->create(['phone' => '091 440 221']);

        return compact('user', 'profile', 'account');
    }

    /**
     * @return array<string, string> raw ciphertexts keyed by "table.column"
     */
    private function rawCiphertexts(array $rows): array
    {
        return [
            'users.phone' => DB::table('users')->where('id', $rows['user']->id)->value('phone'),
            'driver_profiles.license_number' => DB::table('driver_profiles')->where('id', $rows['profile']->id)->value('license_number'),
            'driver_bank_accounts.account_number' => DB::table('driver_bank_accounts')->where('id', $rows['account']->id)->value('account_number'),
        ];
    }

    private function assertReadable(array $rows, string $message): void
    {
        $this->assertSame('+37491440221', $rows['user']->fresh()->phone, $message);
        $this->assertSame('AM-DL42', $rows['profile']->fresh()->license_number, $message);
        $this->assertSame('AM1234567890'.'4821', $rows['account']->fresh()->account_number, $message);
    }

    public function test_after_rotating_the_key_with_the_old_one_listed_everything_is_still_readable_and_new_data_uses_the_new_key(): void
    {
        $rows = $this->seedUnderOldKey();
        $oldCiphertexts = $this->rawCiphertexts($rows);

        $this->useKeys($this->newKey, [$this->oldKey]);

        $this->assertReadable($rows, 'APP_PREVIOUS_KEYS keeps existing data readable.');
        $this->assertSame($oldCiphertexts, $this->rawCiphertexts($rows), 'Rotating the key does not touch stored data by itself.');
        $newUser = User::factory()->create(['phone' => '+37493111222']);
        $raw = DB::table('users')->where('id', $newUser->id)->value('phone');
        $this->assertSame('+37493111222', $this->encrypterFor($this->newKey)->decryptString($raw), 'New writes are encrypted with the new key.');
    }

    public function test_dropping_the_old_key_before_re_encrypting_loses_access_which_is_why_the_command_exists(): void
    {
        $rows = $this->seedUnderOldKey();

        $this->useKeys($this->newKey, []);

        $this->expectException(DecryptException::class);
        $rows['user']->fresh()->phone;
    }

    public function test_pii_reencrypt_moves_everything_to_the_new_key_so_the_old_one_can_be_retired(): void
    {
        $rows = $this->seedUnderOldKey();
        $before = $this->rawCiphertexts($rows);
        $this->useKeys($this->newKey, [$this->oldKey]);

        $this->assertSame(0, Artisan::call('pii:reencrypt'));

        $after = $this->rawCiphertexts($rows);
        foreach ($before as $label => $ciphertext) {
            $this->assertNotSame($ciphertext, $after[$label], "{$label} must be re-encrypted.");
            $this->assertSame(
                $this->encrypterFor($this->oldKey)->decryptString($ciphertext),
                $this->encrypterFor($this->newKey)->decryptString($after[$label]),
                "{$label}: the value must be unchanged.",
            );
            $this->expectsNoOldKeyAccess($after[$label]);
        }

        // The old key is gone and nothing is lost.
        $this->useKeys($this->newKey, []);
        $this->assertReadable($rows, 'Readable with only the new key.');
    }

    private function expectsNoOldKeyAccess(string $ciphertext): void
    {
        try {
            $this->encrypterFor($this->oldKey)->decryptString($ciphertext);
            $this->fail('Re-encrypted data must not be readable with the old key.');
        } catch (DecryptException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_blind_indexes_do_not_depend_on_app_key_so_lookups_work_before_during_and_after_rotation(): void
    {
        $rows = $this->seedUnderOldKey();
        $hashes = [DB::table('users')->where('id', $rows['user']->id)->value('phone_hash'), DB::table('driver_profiles')->where('id', $rows['profile']->id)->value('license_number_hash')];

        $this->useKeys($this->newKey, [$this->oldKey]);
        $this->assertTrue(User::query()->wherePhone('091 440 221')->sole()->is($rows['user']));
        Artisan::call('pii:reencrypt');
        $this->useKeys($this->newKey, []);

        $this->assertSame($hashes, [DB::table('users')->where('id', $rows['user']->id)->value('phone_hash'), DB::table('driver_profiles')->where('id', $rows['profile']->id)->value('license_number_hash')]);
        $this->assertTrue(User::query()->wherePhone('+37491440221')->sole()->is($rows['user']));
        $this->assertTrue(DriverProfile::query()->whereLicenseNumber('am-dl 42')->sole()->is($rows['profile']));
    }

    public function test_a_key_rotation_needs_the_new_key_to_differ_from_the_blind_index_key(): void
    {
        $this->useKeys($this->newKey, [$this->oldKey]);

        $this->assertNotSame(config('app.blind_index_key'), config('app.key'));
        $this->assertNotContains(config('app.blind_index_key'), config('app.previous_keys'));
        $this->assertNotNull(app(BlindIndex::class));
    }

    public function test_previous_keys_are_read_from_the_env_value_as_a_comma_separated_list(): void
    {
        $config = file_get_contents(base_path('config/app.php'));

        $this->assertStringContainsString("explode(',', (string) env('APP_PREVIOUS_KEYS', ''))", $config);
        $this->assertStringContainsString('APP_PREVIOUS_KEYS', file_get_contents(base_path('docs/security.md')));
    }
}
