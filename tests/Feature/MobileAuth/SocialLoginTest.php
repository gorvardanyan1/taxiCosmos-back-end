<?php

namespace Tests\Feature\MobileAuth;

use App\Contracts\SocialTokenVerifier;
use App\Data\SocialIdentity;
use App\Enums\UserStatus;
use App\Exceptions\MobileAuthException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\MobileAuth\Social\AppleTokenVerifier;
use App\Services\MobileAuth\Social\FacebookTokenVerifier;
use App\Services\MobileAuth\Social\GoogleTokenVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    private const PHONE = '+37491440221';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    /**
     * Make a provider's verifier answer with this identity (or refuse the token).
     */
    private function provider(string $class, ?SocialIdentity $identity): void
    {
        $this->app->instance($class, new class($identity) implements SocialTokenVerifier
        {
            public function __construct(private readonly ?SocialIdentity $identity) {}

            public function verify(string $token): SocialIdentity
            {
                return $this->identity ?? throw MobileAuthException::invalidSocialToken();
            }
        });
    }

    private function social(string $provider, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/auth/social/{$provider}", ['token' => 'native-token', 'app' => 'rider', 'device_id' => 'device-0001', ...$extra]);
    }

    private function google(string $id = 'g-1', ?string $email = 'ann@example.com', bool $verified = true, ?string $name = 'Ann'): void
    {
        $this->provider(GoogleTokenVerifier::class, new SocialIdentity($id, $email, $verified, $name));
    }

    public function test_a_first_google_sign_in_creates_one_user_and_one_social_account(): void
    {
        $this->google();

        $body = $this->social('google')->assertOk()->assertJsonStructure(['access_token', 'access_token_expires_at', 'refresh_token', 'user', 'needs_phone'])->json();

        $user = User::query()->sole();
        $this->assertSame('Ann', $user->name);
        $this->assertSame('ann@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_rider);
        $account = SocialAccount::query()->sole();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('google', $account->provider->value);
        $this->assertSame('g-1', $account->provider_user_id);
        $this->assertSame('ann@example.com', $account->email);
        $this->assertSame($user->id, $body['user']['id']);
    }

    public function test_a_social_sign_in_without_a_phone_is_a_pending_session(): void
    {
        $this->google();

        $body = $this->social('google')->assertOk()->json();

        $this->assertTrue($body['needs_phone']);
        $this->assertNull($body['user']['phone']);
        $this->assertSame(['pending-phone:rider'], PersonalAccessToken::findToken($body['access_token'])->abilities);
    }

    public function test_the_second_sign_in_reuses_the_same_user_and_social_account(): void
    {
        $this->google(email: 'ann@example.com');
        $first = $this->social('google', ['device_id' => 'device-0001'])->json();
        // The user changed their mind about sharing the email later; the account is matched on the provider id.
        $this->google(email: 'new-address@example.com');
        $second = $this->social('google', ['device_id' => 'device-0002'])->json();

        $this->assertSame($first['user']['id'], $second['user']['id']);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, SocialAccount::query()->count());
        $this->assertSame('new-address@example.com', SocialAccount::query()->sole()->email);
    }

    public function test_sign_in_with_apple_is_accepted(): void
    {
        $this->provider(AppleTokenVerifier::class, new SocialIdentity('apple-001', 'relay@privaterelay.appleid.com', true, null));

        $this->social('apple')->assertOk();

        $this->assertSame('apple', SocialAccount::query()->sole()->provider->value);
        $this->assertSame('apple-001', SocialAccount::query()->sole()->provider_user_id);
    }

    public function test_facebook_is_accepted(): void
    {
        $this->provider(FacebookTokenVerifier::class, new SocialIdentity('fb-001', 'fb@example.com', false, 'Fay'));

        $this->social('facebook')->assertOk();

        $this->assertSame('facebook', SocialAccount::query()->sole()->provider->value);
    }

    public function test_the_same_id_at_two_providers_is_two_different_accounts_rows_for_two_users(): void
    {
        $this->provider(GoogleTokenVerifier::class, new SocialIdentity('same-id', null, false, null));
        $this->provider(AppleTokenVerifier::class, new SocialIdentity('same-id', null, false, null));

        $google = $this->social('google')->json();
        $apple = $this->social('apple')->json();

        $this->assertNotSame($google['user']['id'], $apple['user']['id']);
        $this->assertSame(2, SocialAccount::query()->count());
    }

    public function test_a_verified_google_email_links_to_the_existing_account_with_that_email(): void
    {
        $existing = User::factory()->rider()->withPhone(self::PHONE)->create(['email' => 'Ann@Example.com']);
        $this->google(email: 'ann@example.com');

        $body = $this->social('google')->assertOk()->json();

        $this->assertSame($existing->id, $body['user']['id']);
        $this->assertSame(1, User::query()->count());
        $this->assertSame($existing->id, SocialAccount::query()->sole()->user_id);
        $this->assertFalse($body['needs_phone']);
        $this->assertSame(['rider'], PersonalAccessToken::findToken($body['access_token'])->abilities);
    }

    public function test_an_unverified_email_never_links_to_someone_elses_account(): void
    {
        $victim = User::factory()->rider()->withPhone(self::PHONE)->create(['email' => 'ann@example.com']);
        $this->google(email: 'ann@example.com', verified: false);

        $body = $this->social('google')->assertOk()->json();

        $this->assertNotSame($victim->id, $body['user']['id']);
        $this->assertSame(2, User::query()->count());
        $this->assertNull(User::query()->findOrFail($body['user']['id'])->email);
    }

    public function test_facebook_emails_never_link_even_if_they_match(): void
    {
        $victim = User::factory()->rider()->create(['email' => 'ann@example.com']);
        $this->provider(FacebookTokenVerifier::class, new SocialIdentity('fb-9', 'ann@example.com', true, 'Not Ann'));

        $body = $this->social('facebook')->assertOk()->json();

        $this->assertNotSame($victim->id, $body['user']['id']);
    }

    public function test_a_verified_email_held_by_a_deleted_account_does_not_resurrect_it(): void
    {
        $gone = User::factory()->rider()->create(['email' => 'ann@example.com']);
        $gone->delete();
        $this->google(email: 'ann@example.com');

        $this->social('google')->assertForbidden();

        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_suspended_user_cannot_sign_in_socially(): void
    {
        $user = User::factory()->rider()->suspended()->create();
        SocialAccount::query()->forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1']);
        $this->google();

        $this->social('google')->assertForbidden()->assertJsonMissingPath('access_token');

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(UserStatus::Suspended, $user->refresh()->status);
    }

    public function test_an_admin_account_cannot_sign_in_socially(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);
        $this->google(email: 'boss@example.com');

        $this->social('google')->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame($admin->id, User::query()->where('is_admin', true)->value('id'));
    }

    public function test_a_token_the_provider_rejects_is_a_401_and_creates_nothing(): void
    {
        $this->provider(GoogleTokenVerifier::class, null);

        $this->social('google')->assertUnauthorized();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_unknown_providers_and_bad_input_are_refused(): void
    {
        $this->social('twitter')->assertNotFound();
        $this->postJson('/api/v1/auth/social/google', ['app' => 'rider', 'device_id' => 'device-0001'])->assertStatus(422)->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'rider'])->assertStatus(422)->assertJsonValidationErrors('device_id');
        $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'admin', 'device_id' => 'device-0001'])->assertStatus(422)->assertJsonValidationErrors('app');
        $this->postJson('/api/v1/auth/social/google', ['token' => str_repeat('a', 5000), 'app' => 'rider', 'device_id' => 'device-0001'])->assertStatus(422);
    }

    public function test_the_driver_app_sets_the_driver_flag(): void
    {
        $this->google();

        $this->social('google', ['app' => 'driver'])->assertOk();

        $this->assertTrue(User::query()->sole()->is_driver);
    }

    // ---- collecting the phone number ----

    public function test_a_pending_session_can_only_add_a_phone(): void
    {
        $this->google();
        $pending = $this->social('google')->json();

        $this->postJson('/api/v1/auth/phone/verify', [], $this->bearer($pending['access_token']))->assertStatus(422);
        // A full-session route (logout) is open to it so the app can sign out; phone/verify is closed to full sessions:
        $full = $this->signIn(self::PHONE, device: 'device-full');
        $this->app['auth']->forgetGuards(); // the test client would otherwise keep the first request's user
        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'code' => '123456'], $this->bearer($full['access_token']))->assertForbidden();
    }

    public function test_adding_the_phone_swaps_the_pending_session_for_a_full_one(): void
    {
        $this->google();
        $pending = $this->social('google', ['device_id' => 'device-0001'])->json();
        $other = $this->signIn('+37493123456', device: 'device-9999');

        $code = $this->requestCode(self::PHONE);
        $body = $this->postJson('/api/v1/auth/phone/verify', ['phone' => '091 440 221', 'code' => $code], $this->bearer($pending['access_token']))
            ->assertOk()->json();

        $this->assertFalse($body['needs_phone']);
        $this->assertSame(self::PHONE, $body['user']['phone']);
        $this->assertSame($pending['user']['id'], $body['user']['id']);
        $this->assertSame(self::PHONE, User::query()->findOrFail($pending['user']['id'])->phone);
        $this->assertSame(['rider'], PersonalAccessToken::findToken($body['access_token'])->abilities);
        $this->assertNull(PersonalAccessToken::findToken($pending['access_token']));
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pending['refresh_token']])->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($other['access_token']));
        $this->assertSame(2, User::query()->count());
    }

    public function test_a_phone_that_belongs_to_another_account_is_refused(): void
    {
        User::factory()->rider()->withPhone(self::PHONE)->create();
        $this->google();
        $pending = $this->social('google')->json();

        $code = $this->requestCode(self::PHONE);
        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'code' => $code], $this->bearer($pending['access_token']))
            ->assertStatus(409);

        $this->assertNull(User::query()->findOrFail($pending['user']['id'])->phone);
        $this->assertNotNull(PersonalAccessToken::findToken($pending['access_token']));
    }

    public function test_a_wrong_code_does_not_add_the_phone(): void
    {
        $this->google();
        $pending = $this->social('google')->json();
        $code = $this->requestCode(self::PHONE);

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'code' => $code === '000000' ? '000001' : '000000'], $this->bearer($pending['access_token']))
            ->assertStatus(422);

        $this->assertNull(User::query()->findOrFail($pending['user']['id'])->phone);
    }

    public function test_phone_verify_needs_a_token(): void
    {
        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'code' => '123456'])->assertUnauthorized();
    }

    public function test_a_pending_session_can_refresh_and_stays_pending(): void
    {
        $this->google();
        $pending = $this->social('google')->json();

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pending['refresh_token']])->assertOk()->json();

        $this->assertTrue($refreshed['needs_phone']);
        $this->assertSame(['pending-phone:rider'], PersonalAccessToken::findToken($refreshed['access_token'])->abilities);
    }

    public function test_concurrent_first_sign_ins_for_one_identity_leave_one_account(): void
    {
        $this->google();
        $this->social('google', ['device_id' => 'device-0001'])->assertOk();
        $this->social('google', ['device_id' => 'device-0002'])->assertOk();
        $this->social('google', ['device_id' => 'device-0003'])->assertOk();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, SocialAccount::query()->count());
    }
}
