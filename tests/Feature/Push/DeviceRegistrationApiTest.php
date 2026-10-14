<?php

namespace Tests\Feature\Push;

use App\Enums\AppClient;
use App\Models\Device;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P8-T4: POST /api/v1/devices and DELETE /api/v1/devices/{id}.
 */
class DeviceRegistrationApiTest extends PushTestCase
{
    public function test_a_driver_registers_a_device_and_the_row_records_the_app_the_install_and_the_details(): void
    {
        $driver = $this->driverUser();
        $access = $this->signedIn($driver, AppClient::Driver, 'install-A');

        $response = $this->register($access, $this->token('one'), ['platform' => 'ios', 'locale' => 'ru', 'app_version' => '1.4.2'])->assertCreated();

        $device = Device::query()->sole();
        $this->assertSame([$driver->id, 'driver', 'ios', 'install-A', 'ru', '1.4.2'], [$device->user_id, $device->app->value, $device->platform->value, $device->device_id, $device->locale, $device->app_version]);
        $this->assertNotNull($device->last_seen_at);
        $this->assertNull($device->revoked_at);
        $this->assertSame(['id' => $device->id, 'app' => 'driver', 'platform' => 'ios', 'locale' => 'ru', 'app_version' => '1.4.2', 'last_seen_at' => $device->last_seen_at->toIso8601String()], $response->json('device'));
        $this->assertStringNotContainsString('ExponentPushToken', $response->getContent(), 'the token is not echoed back');
    }

    public function test_the_rider_app_registers_as_a_rider_device(): void
    {
        $rider = $this->riderUser();

        $this->register($this->signedIn($rider, AppClient::Rider), $this->token('rider'))->assertCreated();

        $this->assertSame('rider', Device::query()->sole()->app->value);
    }

    public function test_registering_the_same_token_again_updates_the_row_and_creates_no_second_one(): void
    {
        $driver = $this->driverUser();
        $access = $this->signedIn($driver, AppClient::Driver);
        $this->travelTo(now()->subHour());
        $first = $this->register($access, $this->token('one'), ['locale' => 'en', 'app_version' => '1.0'])->assertCreated()->json('device.id');
        $this->travelBack();

        $again = $this->register($access, $this->token('one'), ['locale' => 'hy', 'app_version' => '1.1'])->assertOk();

        $this->assertSame($first, $again->json('device.id'));
        $device = Device::query()->sole();
        $this->assertSame(['hy', '1.1'], [$device->locale, $device->app_version]);
        $this->assertTrue($device->last_seen_at->isAfter(now()->subMinute()), 'last_seen_at moved to now');
    }

    public function test_a_revoked_token_registered_again_is_active_again(): void
    {
        $driver = $this->driverUser();
        $access = $this->signedIn($driver, AppClient::Driver);
        $id = $this->register($access, $this->token('one'))->json('device.id');
        $this->deleteJson("/api/v1/devices/{$id}", [], ['Authorization' => 'Bearer '.$access])->assertNoContent();
        $this->assertSame(0, $this->activeDevices($driver));

        $this->register($access, $this->token('one'))->assertOk();

        $this->assertSame(1, $this->activeDevices($driver));
    }

    public function test_a_rotated_token_on_the_same_install_replaces_the_old_one(): void
    {
        $driver = $this->driverUser();
        $access = $this->signedIn($driver, AppClient::Driver, 'install-A');
        $this->register($access, $this->token('old'))->assertCreated();

        $this->register($access, $this->token('new'))->assertCreated();

        $this->assertSame(1, $this->activeDevices($driver));
        $this->assertSame($this->token('new'), Device::query()->active()->sole()->push_token);
        $this->assertNotNull(Device::query()->where('push_token', $this->token('old'))->sole()->revoked_at);
    }

    public function test_two_installs_of_the_same_person_keep_a_device_each(): void
    {
        $driver = $this->driverUser();

        $this->register($this->signedIn($driver, AppClient::Driver, 'phone-1'), $this->token('p1'))->assertCreated();
        $this->register($this->signedIn($driver, AppClient::Driver, 'phone-2'), $this->token('p2'))->assertCreated();

        $this->assertSame(2, $this->activeDevices($driver));
    }

    public function test_one_person_with_both_apps_has_a_device_per_app(): void
    {
        $both = User::factory()->rider()->driver()->withPhone('+37491609999')->create();

        $this->register($this->signedIn($both, AppClient::Rider, 'install-R'), $this->token('r'))->assertCreated();
        $this->register($this->signedIn($both, AppClient::Driver, 'install-D'), $this->token('d'))->assertCreated();

        $this->assertEqualsCanonicalizing(['rider', 'driver'], Device::query()->active()->get()->map(fn (Device $d) => $d->app->value)->all());
    }

    // ---- shared phones ----

    public function test_a_token_that_belongs_to_another_user_moves_to_the_new_user(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertCreated();

        $this->register($this->signedIn($bob, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertOk(); // the row exists, it is updated

        $this->assertSame(1, Device::query()->count(), 'one phone, one row');
        $device = Device::query()->sole();
        $this->assertSame($bob->id, $device->user_id);
        $this->assertNull($device->revoked_at);
        $this->assertSame(0, $this->activeDevices($alice));
    }

    public function test_the_token_cannot_be_registered_for_someone_else_by_naming_them(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();

        $this->register($this->signedIn($alice, AppClient::Driver), $this->token('mine'), ['user_id' => $bob->id])->assertCreated();

        $this->assertSame($alice->id, Device::query()->sole()->user_id);
    }

    // ---- validation ----

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'no token' => [['push_token' => null], 'push_token'],
            'a token that is not an Expo token' => [['push_token' => 'abc123'], 'push_token'],
            'an FCM style token' => [['push_token' => str_repeat('a', 160)], 'push_token'],
            'an empty Expo token' => [['push_token' => 'ExponentPushToken[]'], 'push_token'],
            'an unclosed Expo token' => [['push_token' => 'ExponentPushToken[abcdefghij'], 'push_token'],
            'a token with spaces' => [['push_token' => 'ExponentPushToken[abc defghij]'], 'push_token'],
            'a token that is too long' => [['push_token' => 'ExponentPushToken['.str_repeat('a', 250).']'], 'push_token'],
            'a token as a list' => [['push_token' => ['ExponentPushToken[abcdefghij]']], 'push_token'],
            'no platform' => [['platform' => null], 'platform'],
            'an unknown platform' => [['platform' => 'windows'], 'platform'],
            'a platform in capitals' => [['platform' => 'IOS'], 'platform'],
            'an unsupported language' => [['locale' => 'fr'], 'locale'],
            'an app version that is too long' => [['app_version' => str_repeat('1', 33)], 'app_version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalid')]
    public function test_bad_input_is_422_and_stores_nothing(array $override, string $field): void
    {
        $access = $this->signedIn($this->driverUser(), AppClient::Driver);
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/devices', [...['push_token' => $this->token('ok'), 'platform' => 'ios'], ...$override], ['Authorization' => 'Bearer '.$access])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, Device::query()->count());
    }

    public function test_the_longest_accepted_token_and_version_are_stored(): void
    {
        $access = $this->signedIn($this->driverUser(), AppClient::Driver);
        $token = 'ExponentPushToken['.str_repeat('a', 200).']';

        $this->register($access, $token, ['app_version' => str_repeat('1', 32)])->assertCreated();

        $this->assertSame($token, Device::query()->sole()->push_token);
    }

    // ---- who may ----

    public function test_guests_junk_tokens_and_a_session_that_still_has_to_add_its_phone_are_refused(): void
    {
        $noPhone = User::factory()->driver()->create(['phone' => null]);
        $pending = app(MobileSessionService::class)->issue($noPhone, AppClient::Driver, 'install-x')->accessToken;

        $this->postJson('/api/v1/devices', ['push_token' => $this->token('a'), 'platform' => 'ios'])->assertUnauthorized();
        $this->register('junk', $this->token('a'))->assertUnauthorized();
        $this->register($pending, $this->token('a'))->assertForbidden();
        app('auth')->forgetGuards();
        $this->deleteJson('/api/v1/devices/1')->assertUnauthorized();
        $this->assertSame(0, Device::query()->count());
    }

    // ---- deregistering ----

    public function test_deregistering_revokes_the_device_and_repeating_it_changes_nothing(): void
    {
        $driver = $this->driverUser();
        $access = $this->signedIn($driver, AppClient::Driver);
        $id = $this->register($access, $this->token('one'))->json('device.id');
        app('auth')->forgetGuards();

        $this->deleteJson("/api/v1/devices/{$id}", [], ['Authorization' => 'Bearer '.$access])->assertNoContent();
        $revokedAt = Device::query()->sole()->revoked_at;
        $this->assertNotNull($revokedAt);
        $this->travel(5)->minutes();
        app('auth')->forgetGuards();
        $this->deleteJson("/api/v1/devices/{$id}", [], ['Authorization' => 'Bearer '.$access])->assertNoContent();

        $this->assertTrue(Device::query()->sole()->revoked_at->equalTo($revokedAt), 'the first revocation time stays');
        $this->assertSame(1, Device::query()->count(), 'the row is kept, only revoked');
    }

    public function test_someone_elses_device_is_not_found_and_stays_registered(): void
    {
        $alice = $this->driverUser();
        $mallory = $this->driverUser();
        $id = $this->register($this->signedIn($alice, AppClient::Driver), $this->token('alice'))->json('device.id');
        $malloryAccess = $this->signedIn($mallory, AppClient::Driver, 'mallory-phone');
        app('auth')->forgetGuards();

        $this->deleteJson("/api/v1/devices/{$id}", [], ['Authorization' => 'Bearer '.$malloryAccess])->assertNotFound();

        $this->assertSame(1, $this->activeDevices($alice));
    }

    public function test_an_unknown_or_malformed_id_is_not_found(): void
    {
        $access = $this->signedIn($this->driverUser(), AppClient::Driver);

        foreach (['999999', 'abc', '1.5', '-1'] as $id) {
            app('auth')->forgetGuards();
            $this->deleteJson("/api/v1/devices/{$id}", [], ['Authorization' => 'Bearer '.$access])->assertNotFound();
        }
    }

    // ---- limits and secrets ----

    public function test_registration_is_limited_per_user(): void
    {
        config(['security.rate_limits.devices' => 3]);
        $access = $this->signedIn($this->driverUser(), AppClient::Driver);
        $other = $this->signedIn($this->driverUser(), AppClient::Driver);

        foreach (range(1, 3) as $i) {
            $this->register($access, $this->token("t{$i}"))->assertSuccessful();
        }
        $blocked = $this->register($access, $this->token('t4'))->assertStatus(429);

        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->register($other, $this->token('other'))->assertCreated();
    }

    public function test_the_push_token_never_reaches_the_log_or_a_serialised_model(): void
    {
        Log::spy();
        $access = $this->signedIn($this->driverUser(), AppClient::Driver);

        $this->register($access, $this->token('secret'))->assertCreated();

        $this->assertArrayNotHasKey('push_token', Device::query()->sole()->toArray());
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }
}
