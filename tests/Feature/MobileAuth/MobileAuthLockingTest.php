<?php

namespace Tests\Feature\MobileAuth;

use App\Contracts\SocialTokenVerifier;
use App\Data\SocialIdentity;
use App\Models\User;
use App\Services\MobileAuth\Social\GoogleTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

/**
 * A real two-connection race cannot run inside RefreshDatabase's transaction, so these tests pin the
 * locks themselves: the statements that serialise concurrent requests must be issued, and in the right
 * place. Without them two parallel requests could both pass the checks below.
 */
class MobileAuthLockingTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    private const PHONE = '+37491440221';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    /**
     * The SQL statements run while $action executes.
     *
     * @return list<string>
     */
    private function queriesDuring(callable $action): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $action();

        return $queries;
    }

    /**
     * @param  list<string>  $queries
     */
    private function assertLocked(array $queries, string $table, string $why): void
    {
        $locked = array_filter($queries, fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, "from \"{$table}\"") && str_contains($sql, 'for update'));

        $this->assertNotEmpty($locked, "{$why}: no SELECT ... FOR UPDATE on {$table} was issued.");
    }

    /**
     * @param  list<string>  $queries
     */
    private function assertAdvisoryLockBeforeLookup(array $queries, string $lookupTable): void
    {
        $lock = null;
        $lookup = null;
        foreach ($queries as $i => $sql) {
            $lock ??= str_contains($sql, 'pg_advisory_xact_lock') ? $i : null;
            $lookup ??= str_starts_with($sql, 'select') && str_contains($sql, "from \"{$lookupTable}\"") && ! str_contains($sql, 'pg_advisory') && $lock !== null ? $i : null;
        }

        $this->assertNotNull($lock, 'No advisory lock was taken for the identity.');
        $this->assertNotNull($lookup, "No {$lookupTable} lookup ran after the lock.");
        $this->assertLessThan($lookup, $lock);
    }

    public function test_phone_sign_in_takes_the_identity_lock_before_looking_the_phone_up(): void
    {
        $code = $this->requestCode(self::PHONE);

        $queries = $this->queriesDuring(fn () => $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, $code))->assertOk());

        $this->assertAdvisoryLockBeforeLookup($queries, 'users');
    }

    public function test_checking_a_code_locks_its_row_so_parallel_guesses_cannot_overshoot_the_attempt_cap(): void
    {
        $code = $this->requestCode(self::PHONE);

        $queries = $this->queriesDuring(fn () => $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, $code === '000000' ? '000001' : '000000'))->assertStatus(422));

        $this->assertLocked($queries, 'otp_codes', 'OTP verification');
    }

    public function test_refreshing_locks_the_token_row_so_a_parallel_replay_cannot_fork_the_family(): void
    {
        $body = $this->signIn(self::PHONE);

        $queries = $this->queriesDuring(fn () => $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $body['refresh_token']])->assertOk());

        $this->assertLocked($queries, 'refresh_tokens', 'Refresh');
    }

    public function test_social_sign_in_takes_the_identity_lock_before_looking_the_account_up(): void
    {
        $this->app->instance(GoogleTokenVerifier::class, new class implements SocialTokenVerifier
        {
            public function verify(string $token): SocialIdentity
            {
                return new SocialIdentity('g-1', null, false, null);
            }
        });

        $queries = $this->queriesDuring(fn () => $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'rider', 'device_id' => 'device-0001'])->assertOk());

        $this->assertAdvisoryLockBeforeLookup($queries, 'social_accounts');
    }

    public function test_adding_a_phone_locks_the_identity_and_the_user_row(): void
    {
        $this->app->instance(GoogleTokenVerifier::class, new class implements SocialTokenVerifier
        {
            public function verify(string $token): SocialIdentity
            {
                return new SocialIdentity('g-1', null, false, null);
            }
        });
        $pending = $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'rider', 'device_id' => 'device-0001'])->assertOk()->json();
        $code = $this->requestCode(self::PHONE);
        $this->app['auth']->forgetGuards();

        $queries = $this->queriesDuring(fn () => $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'code' => $code], $this->bearer($pending['access_token']))->assertOk());

        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'pg_advisory_xact_lock')), 'No advisory lock for the phone.');
        $this->assertLocked($queries, 'users', 'Adding a phone');
    }

    public function test_an_account_that_already_has_a_phone_keeps_it_when_a_pending_token_tries_to_add_another(): void
    {
        $this->app->instance(GoogleTokenVerifier::class, new class implements SocialTokenVerifier
        {
            public function verify(string $token): SocialIdentity
            {
                return new SocialIdentity('g-1', null, false, null);
            }
        });
        $pending = $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'rider', 'device_id' => 'device-0001'])->assertOk()->json();
        // The phone was added some other way after this pending token was issued.
        User::query()->findOrFail($pending['user']['id'])->forceFill(['phone' => '+37493123456'])->save();
        $code = $this->requestCode(self::PHONE);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'code' => $code], $this->bearer($pending['access_token']))
            ->assertStatus(409)->assertJsonValidationErrors('phone');

        $this->assertSame('+37493123456', User::query()->findOrFail($pending['user']['id'])->phone);
    }
}
