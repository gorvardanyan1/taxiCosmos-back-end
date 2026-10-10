<?php

namespace Tests\Feature\Pii;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class PiiLookupTest extends TestCase
{
    use RefreshDatabase;

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.blind_index_key'));
    }

    public function test_the_phone_is_stored_and_hashed_in_e164_whatever_way_it_was_typed(): void
    {
        $user = User::factory()->create(['phone' => '091 440 221']);

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertSame('+37491440221', Crypt::decryptString($raw->phone));
        $this->assertSame($this->hash('+37491440221'), $raw->phone_hash);
        $this->assertSame('+37491440221', $user->fresh()->phone);
    }

    public function test_any_writing_of_the_number_finds_the_same_user_for_login_and_admin_search(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);
        User::factory()->withPhone('+37493111222')->create();

        foreach (['+37491440221', '091 440 221', '91440221', '0037491440221', '+374 91-440-221', '  091440221 '] as $typed) {
            $this->assertTrue(User::query()->wherePhone($typed)->sole()->is($user), "Lookup for [{$typed}] must find the user.");
        }
    }

    public function test_input_that_is_not_a_phone_number_or_belongs_to_nobody_finds_nothing_without_error(): void
    {
        User::factory()->withPhone('+37491440221')->create();

        foreach (['', 'abc', '--', '+37499999999', '+14155552671'] as $typed) {
            $this->assertSame(0, User::query()->wherePhone($typed)->count(), "[{$typed}]");
        }
    }

    public function test_the_same_person_written_differently_cannot_register_twice(): void
    {
        User::factory()->create(['phone' => '+37491440221']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('users_phone_hash_unique');

        User::factory()->create(['phone' => '091 440 221']);
    }

    public function test_a_value_that_is_not_a_phone_number_cannot_be_stored_and_nothing_is_saved(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);

        try {
            $user->update(['phone' => 'call me maybe']);
            $this->fail('An unparseable phone must be refused.');
        } catch (InvalidArgumentException) {
            $this->assertSame('+37491440221', $user->fresh()->phone);
        }
    }

    public function test_clearing_the_phone_clears_its_hash_and_users_without_phone_do_not_collide(): void
    {
        $a = User::factory()->create(['phone' => '+37491440221']);
        $b = User::factory()->create();
        $c = User::factory()->create();

        $a->update(['phone' => null]);

        $this->assertDatabaseHas('users', ['id' => $a->id, 'phone' => null, 'phone_hash' => null]);
        $this->assertDatabaseHas('users', ['id' => $b->id, 'phone_hash' => null]);
        $this->assertSame(0, User::query()->wherePhone('+37491440221')->count());
        $this->assertNotNull($c);
    }

    public function test_the_license_is_found_by_exact_match_ignoring_case_and_spaces(): void
    {
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        DriverProfile::factory()->count(5)->create();

        foreach (['AM-DL42', 'am-dl42', ' am-dl 42 '] as $typed) {
            $this->assertTrue(DriverProfile::query()->whereLicenseNumber($typed)->sole()->is($profile), $typed);
        }
        $this->assertSame(0, DriverProfile::query()->whereLicenseNumber('AM-DL4')->count(), 'Exact match only, no prefix search.');
    }

    public function test_lookups_use_the_hash_column_and_never_the_ciphertext(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        User::query()->wherePhone('091 440 221')->first();
        DriverProfile::query()->whereLicenseNumber('AM-DL42')->first();

        $this->assertStringContainsString('"phone_hash" = ?', $queries[0]);
        $this->assertStringNotContainsString('"phone" =', $queries[0]);
        $this->assertStringContainsString('"license_number_hash" = ?', $queries[1]);
        $this->assertStringNotContainsString('"license_number" =', $queries[1]);
        $this->assertCount(2, $queries, 'One indexed query each; no scan-and-decrypt loop.');
    }

    public function test_the_lookups_work_even_when_the_ciphertext_cannot_be_decrypted_proving_no_row_is_decrypted(): void
    {
        $user = User::factory()->create(['phone' => '+37491440221']);
        $profile = DriverProfile::factory()->create(['license_number' => 'AM-DL42']);
        DB::table('users')->where('id', $user->id)->update(['phone' => 'corrupted-not-a-ciphertext']);
        DB::table('driver_profiles')->where('id', $profile->id)->update(['license_number' => 'corrupted-not-a-ciphertext']);

        $this->assertSame($user->id, User::query()->wherePhone('091 440 221')->value('id'));
        $this->assertSame($profile->id, DriverProfile::query()->whereLicenseNumber('am-dl 42')->value('id'));
    }

    public function test_the_database_plans_these_lookups_as_index_scans_on_the_unique_hash_index(): void
    {
        User::factory()->count(3)->create();
        DB::statement('SET LOCAL enable_seqscan = off');

        $user = collect(DB::select('EXPLAIN '.User::query()->wherePhone('091 440 221')->toRawSql()))->pluck('QUERY PLAN')->implode("\n");
        $driver = collect(DB::select('EXPLAIN '.DriverProfile::query()->whereLicenseNumber('AM-DL42')->toRawSql()))->pluck('QUERY PLAN')->implode("\n");

        $this->assertStringContainsString('users_phone_hash_unique', $user);
        $this->assertStringContainsString('driver_profiles_license_number_hash_unique', $driver);
    }
}
