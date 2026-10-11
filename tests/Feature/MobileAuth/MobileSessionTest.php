<?php

namespace Tests\Feature\MobileAuth;

use App\Enums\UserStatus;
use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

class MobileSessionTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    private const PHONE = '+37491440221';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    private function refresh(string $token): TestResponse
    {
        return $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $token]);
    }

    private function logout(string $accessToken, string $path = 'logout'): TestResponse
    {
        return $this->postJson("/api/v1/auth/{$path}", [], $this->bearer($accessToken));
    }

    public function test_the_refresh_token_is_a_64_byte_secret_stored_only_as_a_hash(): void
    {
        $body = $this->signIn(self::PHONE);

        $this->assertSame(86, strlen($body['refresh_token']));
        $this->assertSame(64, strlen(base64_decode(strtr($body['refresh_token'], '-_', '+/').'==')));

        $row = RefreshToken::query()->sole();
        $this->assertSame(hash('sha256', $body['refresh_token']), $row->token_hash);
        $this->assertStringNotContainsString($body['refresh_token'], json_encode($row->getAttributes()));
    }

    public function test_the_refresh_token_lives_thirty_days_and_belongs_to_the_device(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $body = $this->signIn(self::PHONE, 'driver', 'iphone-12345');

        $row = RefreshToken::query()->sole();
        $this->assertSame('2026-11-10 10:00:00', $row->expires_at->toDateTimeString());
        $this->assertSame('iphone-12345', $row->device_id);
        $this->assertSame('driver', $row->app->value);
        $this->assertSame(PersonalAccessToken::findToken($body['access_token'])->id, $row->access_token_id);
    }

    public function test_refreshing_rotates_the_pair_and_retires_the_old_one(): void
    {
        $old = $this->signIn(self::PHONE);

        $new = $this->refresh($old['refresh_token'])->assertOk()->assertJsonStructure(['access_token', 'access_token_expires_at', 'refresh_token', 'user', 'needs_phone'])->json();

        $this->assertNotSame($old['access_token'], $new['access_token']);
        $this->assertNotSame($old['refresh_token'], $new['refresh_token']);
        $this->assertSame($old['user']['id'], $new['user']['id']);

        $rows = RefreshToken::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->used_at);
        $this->assertNull($rows[1]->used_at);
        $this->assertSame($rows[0]->family_id, $rows[1]->family_id);
        $this->assertSame($rows[0]->device_id, $rows[1]->device_id);

        $this->assertNull(PersonalAccessToken::findToken($old['access_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($new['access_token']));
        $this->assertSame(['rider'], PersonalAccessToken::findToken($new['access_token'])->abilities);
    }

    public function test_the_rotated_chain_keeps_working(): void
    {
        $pair = $this->signIn(self::PHONE);

        foreach (range(1, 3) as $i) {
            $pair = $this->refresh($pair['refresh_token'])->assertOk()->json();
        }

        $this->logout($pair['access_token'])->assertNoContent();
    }

    public function test_replaying_a_used_refresh_token_revokes_the_whole_family(): void
    {
        $first = $this->signIn(self::PHONE);
        $second = $this->refresh($first['refresh_token'])->assertOk()->json();

        // The thief (or a buggy client) presents the token that was already exchanged.
        $this->refresh($first['refresh_token'])->assertUnauthorized();

        $this->assertNull(PersonalAccessToken::findToken($second['access_token']), 'the newest access token must die too');
        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->refresh($second['refresh_token'])->assertUnauthorized();
    }

    public function test_reuse_detection_only_hits_the_stolen_family_not_other_devices(): void
    {
        $phone = $this->signIn(self::PHONE, device: 'device-phone');
        $tablet = $this->signIn(self::PHONE, device: 'device-tablet');
        $this->refresh($phone['refresh_token'])->assertOk();

        $this->refresh($phone['refresh_token'])->assertUnauthorized();

        $this->assertNotNull(PersonalAccessToken::findToken($tablet['access_token']), 'the other device keeps its session');
        $this->refresh($tablet['refresh_token'])->assertOk();
    }

    public function test_a_revoked_refresh_token_is_refused(): void
    {
        $body = $this->signIn(self::PHONE);
        $this->logout($body['access_token'])->assertNoContent();

        $this->refresh($body['refresh_token'])->assertUnauthorized();
    }

    public function test_an_expired_refresh_token_is_refused(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $body = $this->signIn(self::PHONE);

        $this->travelTo('2026-11-10 10:00:01');
        $this->refresh($body['refresh_token'])->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_refresh_token_works_just_before_it_expires(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $body = $this->signIn(self::PHONE);

        $this->travelTo('2026-11-10 09:59:59');
        $this->refresh($body['refresh_token'])->assertOk();
    }

    public function test_unknown_or_malformed_refresh_tokens_are_refused(): void
    {
        $this->refresh('nonsense')->assertUnauthorized();
        $this->refresh(str_repeat('a', 86))->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', [])->assertStatus(422)->assertJsonValidationErrors('refresh_token');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => ['x']])->assertStatus(422);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('a', 500)])->assertStatus(422);
    }

    public function test_a_suspended_user_cannot_refresh(): void
    {
        $body = $this->signIn(self::PHONE);
        User::query()->sole()->forceFill(['status' => UserStatus::Suspended])->saveQuietly();

        $this->refresh($body['refresh_token'])->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_user_who_became_an_admin_cannot_refresh(): void
    {
        $body = $this->signIn(self::PHONE);
        User::query()->sole()->forceFill(['is_admin' => true])->saveQuietly();

        $this->refresh($body['refresh_token'])->assertUnauthorized();
    }

    public function test_the_access_token_stops_working_after_an_hour(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $body = $this->signIn(self::PHONE);

        $this->travelTo('2026-10-11 10:59:59');
        $this->logoutProbe($body['access_token'], 204);

        $this->travelTo('2026-10-11 11:00:01');
        $this->logoutProbe($body['access_token'], 401);
    }

    private function logoutProbe(string $token, int $expected): void
    {
        $this->app['auth']->forgetGuards();
        $this->logout($token)->assertStatus($expected);
    }

    public function test_logging_out_one_device_leaves_the_others_signed_in(): void
    {
        $phone = $this->signIn(self::PHONE, device: 'device-phone');
        $tablet = $this->signIn(self::PHONE, device: 'device-tablet');

        $this->logout($phone['access_token'])->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->assertNull(PersonalAccessToken::findToken($phone['access_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($tablet['access_token']));
        $this->refresh($phone['refresh_token'])->assertUnauthorized();
        $this->refresh($tablet['refresh_token'])->assertOk();
    }

    public function test_logout_all_ends_every_device(): void
    {
        $phone = $this->signIn(self::PHONE, device: 'device-phone');
        $tablet = $this->signIn(self::PHONE, device: 'device-tablet');
        $other = $this->signIn('+37493123456', device: 'device-other');

        $this->logout($phone['access_token'], 'logout-all')->assertNoContent();

        $this->assertNull(PersonalAccessToken::findToken($phone['access_token']));
        $this->assertNull(PersonalAccessToken::findToken($tablet['access_token']));
        $this->refresh($phone['refresh_token'])->assertUnauthorized();
        $this->refresh($tablet['refresh_token'])->assertUnauthorized();
        // Another person's session is not touched.
        $this->assertNotNull(PersonalAccessToken::findToken($other['access_token']));
        $this->refresh($other['refresh_token'])->assertOk();
    }

    public function test_signing_in_again_on_the_same_device_ends_its_previous_session_only(): void
    {
        $first = $this->signIn(self::PHONE, device: 'device-phone');
        $tablet = $this->signIn(self::PHONE, device: 'device-tablet');
        $second = $this->signIn(self::PHONE, device: 'device-phone');

        $this->assertNull(PersonalAccessToken::findToken($first['access_token']));
        $this->refresh($first['refresh_token'])->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($second['access_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($tablet['access_token']));
    }

    public function test_logout_requires_a_token(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout-all')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout', [], $this->bearer('1|not-a-real-token'))->assertUnauthorized();
    }

    public function test_suspending_a_user_revokes_every_session_at_once(): void
    {
        $phone = $this->signIn(self::PHONE, device: 'device-phone');
        $tablet = $this->signIn(self::PHONE, device: 'device-tablet');

        User::query()->sole()->forceFill(['status' => UserStatus::Suspended])->save();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->refresh($phone['refresh_token'])->assertUnauthorized();
        $this->refresh($tablet['refresh_token'])->assertUnauthorized();
    }

    public function test_reactivating_does_not_bring_old_sessions_back(): void
    {
        $body = $this->signIn(self::PHONE);
        $user = User::query()->sole();
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $user->forceFill(['status' => UserStatus::Active])->save();

        $this->refresh($body['refresh_token'])->assertUnauthorized();
        $this->signIn(self::PHONE);
    }

    public function test_deleting_a_user_revokes_every_session(): void
    {
        $body = $this->signIn(self::PHONE);

        User::query()->sole()->delete();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->refresh($body['refresh_token'])->assertUnauthorized();
    }

    public function test_an_admin_holding_a_token_is_refused_on_mobile_routes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('stolen', ['rider'])->plainTextToken;

        $this->logout($token)->assertForbidden();
    }

    public function test_a_browser_session_is_not_accepted_on_the_mobile_api(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->actingAs($admin, 'web')->postJson('/api/v1/auth/logout-all')->assertUnauthorized();
    }

    public function test_sanctum_is_configured_for_tokens_only(): void
    {
        // Belt and braces next to the `mobile` middleware: no cookie/session guard is ever consulted.
        $this->assertSame([], config('sanctum.guard'));
    }

    public function test_the_old_boilerplate_user_endpoint_is_gone(): void
    {
        $body = $this->signIn(self::PHONE);

        $this->getJson('/api/v1/user', $this->bearer($body['access_token']))->assertNotFound();
    }
}
