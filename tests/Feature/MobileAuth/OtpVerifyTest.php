<?php

namespace Tests\Feature\MobileAuth;

use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

class OtpVerifyTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    private const PHONE = '+37491440221';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    private function verify(string $code, string $phone = self::PHONE, string $app = 'rider', string $device = 'device-0001'): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload($phone, $code, $app, $device));
    }

    public function test_a_new_phone_creates_exactly_one_rider_and_returns_the_shared_shape(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $code = $this->requestCode(self::PHONE);

        $body = $this->verify($code)->assertOk()->assertJsonStructure([
            'access_token', 'access_token_expires_at', 'refresh_token',
            'user' => ['id', 'name', 'email', 'phone', 'locale', 'timezone', 'is_rider', 'is_driver', 'status'],
            'needs_phone',
        ])->json();

        $user = User::query()->sole();
        $this->assertSame($user->id, $body['user']['id']);
        $this->assertSame(self::PHONE, $body['user']['phone']);
        $this->assertTrue($user->is_rider);
        $this->assertFalse($user->is_driver);
        $this->assertFalse($user->is_admin);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertFalse($body['needs_phone']);
        $this->assertSame('2026-10-11T11:00:00+00:00', $body['access_token_expires_at']);
        $this->assertNotNull($user->last_login_at);
        $this->assertSame($user->id, User::query()->wherePhone('091 440 221')->value('id'));
    }

    public function test_the_driver_app_sets_the_driver_flag_and_keeps_an_existing_rider_flag(): void
    {
        $user = User::factory()->rider()->withPhone(self::PHONE)->create();

        $this->verify($this->requestCode(self::PHONE, 'driver'), app: 'driver')->assertOk();

        $user->refresh();
        $this->assertTrue($user->is_driver);
        $this->assertTrue($user->is_rider);
        $this->assertSame(1, User::query()->count());
    }

    public function test_the_access_token_carries_only_the_app_ability_and_the_configured_lifetime(): void
    {
        config(['taxikosmos.mobile_auth.access_ttl_minutes' => 15]);
        $this->travelTo('2026-10-11 10:00:00');

        $body = $this->signIn(self::PHONE, 'driver');

        $token = PersonalAccessToken::findToken($body['access_token']);
        $this->assertSame(['driver'], $token->abilities);
        $this->assertSame('2026-10-11 10:15:00', $token->expires_at->toDateTimeString());
    }

    public function test_a_retried_request_never_creates_a_second_user(): void
    {
        $code = $this->requestCode(self::PHONE);

        $this->verify($code)->assertOk();
        // The app timed out and sends the same request again: the code is used up, nothing is duplicated.
        $this->verify($code)->assertStatus(422);

        $this->assertSame(1, User::query()->count());
    }

    public function test_signing_in_again_with_a_new_code_finds_the_same_account(): void
    {
        $first = $this->signIn(self::PHONE, device: 'device-0001');
        $second = $this->signIn(self::PHONE, device: 'device-0002');

        $this->assertSame($first['user']['id'], $second['user']['id']);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_code_works_once(): void
    {
        $code = $this->requestCode(self::PHONE);

        $this->verify($code)->assertOk();
        $this->verify($code)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(1, OtpCode::query()->whereNotNull('consumed_at')->count());
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $code = $this->requestCode(self::PHONE);
        $wrong = $code === '000000' ? '000001' : '000000';

        $this->verify($wrong)->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertSame(1, OtpCode::query()->sole()->attempts);
        $this->assertSame(0, User::query()->count());
        $this->verify($code)->assertOk();
    }

    public function test_five_wrong_attempts_throw_the_code_away_even_for_the_right_one(): void
    {
        $code = $this->requestCode(self::PHONE);
        $wrong = $code === '000000' ? '000001' : '000000';

        foreach (range(1, 5) as $i) {
            $this->verify($wrong)->assertStatus(422);
        }

        $otp = OtpCode::query()->sole();
        $this->assertSame(5, $otp->attempts);
        $this->assertNotNull($otp->consumed_at);
        $this->verify($code)->assertStatus(422);
        $this->assertSame(0, User::query()->count());
    }

    public function test_four_wrong_attempts_leave_the_code_usable(): void
    {
        $code = $this->requestCode(self::PHONE);
        $wrong = $code === '000000' ? '000001' : '000000';

        foreach (range(1, 4) as $i) {
            $this->verify($wrong)->assertStatus(422);
        }

        $this->verify($code)->assertOk();
    }

    public function test_the_code_expires_after_five_minutes(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $code = $this->requestCode(self::PHONE);

        $this->travelTo('2026-10-11 10:05:01');
        $this->verify($code)->assertStatus(422);
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_code_still_works_just_before_it_expires(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $code = $this->requestCode(self::PHONE);

        $this->travelTo('2026-10-11 10:04:59');
        $this->verify($code)->assertOk();
    }

    public function test_a_code_is_only_good_for_the_phone_it_was_sent_to(): void
    {
        $code = $this->requestCode(self::PHONE);

        $this->verify($code, '+37493123456')->assertStatus(422);
        $this->assertSame(0, User::query()->count());
    }

    public function test_verifying_without_asking_for_a_code_fails(): void
    {
        $this->verify('123456')->assertStatus(422);
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_suspended_user_gets_no_token(): void
    {
        $user = User::factory()->rider()->withPhone(self::PHONE)->suspended()->create();
        $code = $this->requestCode(self::PHONE);

        $this->verify($code)->assertForbidden()->assertJsonMissingPath('access_token');

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(0, RefreshToken::query()->count());
        $this->assertSame(UserStatus::Suspended, $user->refresh()->status);
    }

    public function test_deactivated_and_pending_deletion_accounts_get_no_token(): void
    {
        foreach ([UserStatus::Deactivated, UserStatus::PendingDeletion] as $status) {
            $user = User::factory()->rider()->withPhone(self::PHONE)->create();
            $user->forceFill(['status' => $status])->save();

            $this->verify($this->requestCode(self::PHONE))->assertForbidden();
            $this->assertSame(0, PersonalAccessToken::query()->count());

            $user->forceDelete();
        }
    }

    public function test_an_admin_account_gets_no_mobile_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->admin()->withPhone(self::PHONE)->create();

        $this->verify($this->requestCode(self::PHONE))->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_deleted_account_keeps_its_phone_and_is_not_recreated(): void
    {
        $gone = User::factory()->rider()->withPhone(self::PHONE)->create();
        $gone->delete();

        $this->verify($this->requestCode(self::PHONE))->assertForbidden();

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_invalid_input_is_a_validation_error_and_does_not_burn_the_code(): void
    {
        $code = $this->requestCode(self::PHONE);

        foreach ([
            [['code' => '12345'], 'code'],
            [['code' => '1234567'], 'code'],
            [['code' => 'abcdef'], 'code'],
            [['code' => ['123456']], 'code'],
            [['device_id' => 'short'], 'device_id'],
            [['device_id' => str_repeat('d', 101)], 'device_id'],
            [['app' => 'admin'], 'app'],
            [['locale' => 'xx'], 'locale'],
            [['phone' => 'nope'], 'phone'],
        ] as [$override, $field]) {
            $this->postJson('/api/v1/auth/otp/verify', [...$this->verifyPayload(self::PHONE, $code), ...$override])
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, OtpCode::query()->sole()->attempts);
        $this->verify($code)->assertOk();
    }

    public function test_the_locale_is_stored_for_a_new_account_only(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', [...$this->verifyPayload(self::PHONE, $this->requestCode(self::PHONE)), 'locale' => 'hy'])->assertOk();
        $this->assertSame('hy', User::query()->sole()->locale);

        $this->postJson('/api/v1/auth/otp/verify', [...$this->verifyPayload(self::PHONE, $this->requestCode(self::PHONE)), 'locale' => 'ru'])->assertOk();
        $this->assertSame('hy', User::query()->sole()->locale);
    }

    public function test_the_stored_phone_is_encrypted_and_found_through_the_blind_index(): void
    {
        $this->signIn(self::PHONE);

        $raw = \DB::table('users')->first();
        $this->assertStringNotContainsString('37491440221', $raw->phone);
        $this->assertNotNull($raw->phone_hash);
    }
}
