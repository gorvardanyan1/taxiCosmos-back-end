<?php

namespace Tests\Feature\Push;

use App\Enums\AppClient;
use App\Enums\PushType;
use App\Enums\UserStatus;
use App\Jobs\SendPushNotification;
use App\Models\Device;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\Push\PushDelivery;
use App\Services\Push\PushNotifier;
use Illuminate\Support\Facades\Queue;

/**
 * P8-T4: "Logging out deregisters the device token; a new user logging in on the same phone never receives the previous
 * user's pushes". Real sessions, real endpoints, real devices rows; only the push service is faked.
 */
class PushLogoutIsolationTest extends PushTestCase
{
    private function logout(string $access): void
    {
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$access])->assertNoContent();
    }

    private function notify(User $user, PushType $type = PushType::TripOffer): int
    {
        return app(PushNotifier::class)->notify($user, $type, ['trip_id' => 1]);
    }

    public function test_logging_out_deregisters_the_device_and_the_person_gets_no_more_pushes(): void
    {
        $alice = $this->driverUser();
        $access = $this->signedIn($alice, AppClient::Driver, 'phone-1');
        $this->register($access, $this->token('alice'))->assertCreated();
        $this->assertSame(1, $this->notify($alice));
        $this->assertCount(1, $this->push->sent);

        $this->logout($access);

        $this->assertSame(0, $this->activeDevices($alice));
        $this->assertNotNull(Device::query()->sole()->revoked_at);
        $this->assertSame(0, $this->notify($alice), 'nothing is queued for a person with no device');
        $this->assertCount(1, $this->push->sent, 'and nothing more was sent');
    }

    public function test_a_new_user_on_the_same_phone_never_receives_the_previous_users_pushes(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();
        $aliceAccess = $this->signedIn($alice, AppClient::Driver, 'shared-phone');
        $this->register($aliceAccess, $this->token('phone'))->assertCreated();
        $this->logout($aliceAccess);

        $bobAccess = $this->signedIn($bob, AppClient::Driver, 'shared-phone');
        $this->register($bobAccess, $this->token('phone'))->assertOk();

        $this->notify($alice);
        $this->assertSame([], $this->push->tokens(), "Alice's push does not reach the phone Bob holds");
        $this->notify($bob);
        $this->assertSame([$this->token('phone')], $this->push->tokens(), "Bob's does");
    }

    public function test_signing_in_as_someone_else_on_the_install_ends_the_previous_registration_before_the_new_one_registers(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertCreated();
        $this->assertSame(1, $this->activeDevices($alice));

        // Alice never logged out (her session just lapsed); Bob signs in and has not registered yet.
        $this->signedIn($bob, AppClient::Driver, 'shared-phone');

        $this->assertSame(0, $this->activeDevices($alice));
        $this->assertSame(0, $this->notify($alice));
        $this->assertSame([], $this->push->sent);
    }

    public function test_only_the_installs_that_logged_out_are_deregistered(): void
    {
        $alice = $this->driverUser();
        $phoneOne = $this->signedIn($alice, AppClient::Driver, 'phone-1');
        $phoneTwo = $this->signedIn($alice, AppClient::Driver, 'phone-2');
        $this->register($phoneOne, $this->token('one'))->assertCreated();
        $this->register($phoneTwo, $this->token('two'))->assertCreated();

        $this->logout($phoneOne);

        $this->assertSame([$this->token('two')], Device::query()->active()->pluck('push_token')->all());
        $this->notify($alice);
        $this->assertSame([$this->token('two')], $this->push->tokens());
    }

    public function test_logging_out_of_the_rider_app_keeps_the_same_persons_driver_app_registered(): void
    {
        $both = User::factory()->rider()->driver()->withPhone('+37491608888')->create();
        $rider = $this->signedIn($both, AppClient::Rider, 'install-1');
        $driver = $this->signedIn($both, AppClient::Driver, 'install-1');
        $this->register($rider, $this->token('rider'))->assertCreated();
        $this->register($driver, $this->token('driver'))->assertCreated();

        $this->logout($rider);

        $this->assertSame([$this->token('driver')], Device::query()->active()->pluck('push_token')->all());
    }

    public function test_logging_out_everywhere_deregisters_every_device(): void
    {
        $alice = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'phone-1'), $this->token('one'))->assertCreated();
        $access = $this->signedIn($alice, AppClient::Driver, 'phone-2');
        $this->register($access, $this->token('two'))->assertCreated();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '.$access])->assertNoContent();

        $this->assertSame(0, $this->activeDevices($alice));
    }

    public function test_refreshing_the_session_keeps_the_registration(): void
    {
        $alice = $this->driverUser();
        $session = app(MobileSessionService::class)->issue($alice, AppClient::Driver, 'phone-1');
        $this->register($session->accessToken, $this->token('one'))->assertCreated();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session->refreshToken])->assertOk();

        $this->assertSame(1, $this->activeDevices($alice));
    }

    public function test_replaying_a_used_refresh_token_ends_the_family_and_its_registration(): void
    {
        $alice = $this->driverUser();
        $session = app(MobileSessionService::class)->issue($alice, AppClient::Driver, 'phone-1');
        $this->register($session->accessToken, $this->token('one'))->assertCreated();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session->refreshToken])->assertOk();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session->refreshToken])->assertUnauthorized();

        $this->assertSame(0, $this->activeDevices($alice), 'a stolen refresh token ends the registration too');
    }

    public function test_a_push_queued_before_the_logout_is_not_sent_after_it(): void
    {
        $alice = $this->driverUser();
        $access = $this->signedIn($alice, AppClient::Driver, 'phone-1');
        $this->register($access, $this->token('alice'))->assertCreated();
        Queue::fake();
        $this->notify($alice);
        $job = Queue::pushed(SendPushNotification::class)->sole();
        $this->logout($access);

        $job->handle(app(PushDelivery::class));

        $this->assertSame([], $this->push->sent);
    }

    public function test_a_push_queued_for_one_user_is_not_sent_if_the_token_moved_to_another_user_meanwhile(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertCreated();
        Queue::fake();
        $this->notify($alice);
        $job = Queue::pushed(SendPushNotification::class)->sole();
        $this->register($this->signedIn($bob, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertOk();

        $job->handle(app(PushDelivery::class));

        $this->assertSame([], $this->push->sent, "Bob's phone must not get Alice's push");
    }

    // ---- suspension and deletion ----

    public function test_a_push_queued_before_the_suspension_is_not_sent_after_it(): void
    {
        $alice = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'phone-1'), $this->token('alice'))->assertCreated();
        Queue::fake();
        $this->notify($alice);
        $job = Queue::pushed(SendPushNotification::class)->sole();
        $alice->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => 'x'])->save();

        $job->handle(app(PushDelivery::class));

        $this->assertSame([], $this->push->sent, 'an offer queued a moment before the suspension never reaches the phone');
    }

    public function test_signing_in_again_on_the_same_phone_ends_the_old_registration_until_the_app_registers_again(): void
    {
        $alice = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'phone-1'), $this->token('alice'))->assertCreated();

        $second = $this->signedIn($alice, AppClient::Driver, 'phone-1');

        $this->assertSame(0, $this->activeDevices($alice), 'the old session ended, so did its registration');
        $this->register($second, $this->token('alice'))->assertOk();
        $this->assertSame(1, $this->activeDevices($alice));
        $this->assertSame(1, Device::query()->count(), 'the same row is brought back, not duplicated');
    }

    public function test_a_suspended_person_is_told_once_and_gets_nothing_else(): void
    {
        $alice = $this->driverUser();
        $access = $this->signedIn($alice, AppClient::Driver, 'phone-1');
        $this->register($access, $this->token('alice'))->assertCreated();

        $alice->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => 'x'])->save();

        $this->assertCount(1, $this->push->sent, 'the suspension notice reached the phone');
        $this->assertSame('account_suspended', $this->push->sent[0]->data['type']);
        $this->assertSame(0, $alice->tokens()->count(), 'the sessions are gone');
        $this->assertSame(0, $this->notify($alice, PushType::SupportReply), 'no other push to a suspended account');
        $this->assertCount(1, $this->push->sent);
    }

    public function test_a_later_login_on_a_suspended_persons_phone_takes_the_token_over(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertCreated();
        $alice->forceFill(['status' => UserStatus::Suspended])->save();
        $this->push->sent = [];

        $this->register($this->signedIn($bob, AppClient::Driver, 'shared-phone'), $this->token('phone'))->assertOk();

        $this->assertSame(0, $this->notify($alice, PushType::AccountSuspended), 'the suspended person no longer has a device');
        $this->assertSame(1, $this->notify($bob));
        $this->assertSame([$this->token('phone')], $this->push->tokens());
    }

    public function test_deleting_the_account_deregisters_its_devices(): void
    {
        $alice = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver), $this->token('alice'))->assertCreated();

        $alice->delete();

        $this->assertSame(0, Device::query()->active()->count());
    }
}
