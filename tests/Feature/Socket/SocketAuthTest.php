<?php

namespace Tests\Feature\Socket;

use App\Enums\AdminRole;
use App\Enums\AppClient;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * P8-T3: POST /api/v1/internal/socket-auth and GET /admin/socket-ticket. Real PostgreSQL, real sessions and tokens.
 */
class SocketAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOKEN = 'unit-test-socket-service-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['security.socket.service_token' => self::SERVICE_TOKEN, 'security.socket.allowed_ips' => ['127.0.0.1']]);
    }

    private function signIn(User $user, AppClient $app): string
    {
        app('auth')->forgetGuards();

        return app(MobileSessionService::class)->issue($user, $app, 'install-1')->accessToken;
    }

    /** @param array<string, mixed> $body */
    private function ask(array $body, ?string $serviceToken = self::SERVICE_TOKEN, array $server = [])
    {
        app('auth')->forgetGuards();

        return $this->withServerVariables($server)->postJson('/api/v1/internal/socket-auth', $body, $serviceToken === null ? [] : ['X-Service-Token' => $serviceToken]);
    }

    private function driver(): User
    {
        return User::factory()->driver()->withPhone('+37491600001')->create();
    }

    public function test_a_valid_driver_token_returns_the_identity(): void
    {
        $driver = $this->driver();
        $token = $this->signIn($driver, AppClient::Driver);

        $response = $this->ask(['token' => $token])->assertOk();

        $this->assertSame(['user_id' => $driver->id, 'kind' => 'mobile', 'app' => 'driver', 'modes' => ['driver'], 'status' => 'active'], collect($response->json())->except('expires_at')->all());
        $this->assertSame(PersonalAccessToken::findToken($token)->expires_at->toIso8601String(), $response->json('expires_at'));
    }

    public function test_an_account_that_rides_and_drives_lists_both_modes_and_the_app_in_use(): void
    {
        $user = User::factory()->rider()->withPhone('+37491600002')->create();
        $user->forceFill(['is_driver' => true])->save();

        $this->ask(['token' => $this->signIn($user, AppClient::Rider)])->assertOk()->assertJson(['app' => 'rider', 'modes' => ['rider', 'driver']]);
        $this->ask(['token' => $this->signIn($user, AppClient::Driver)])->assertOk()->assertJson(['app' => 'driver']);
    }

    public function test_the_service_token_may_also_come_as_a_bearer_header(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/internal/socket-auth', ['token' => $token], ['Authorization' => 'Bearer '.self::SERVICE_TOKEN])->assertOk();
    }

    public function test_a_call_without_the_service_token_is_refused_and_no_user_is_looked_up(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);

        $this->ask(['token' => $token], null)->assertUnauthorized()->assertJsonMissingPath('user_id');
        $this->ask(['token' => $token], 'wrong-service-token-0123456789abcdefghij')->assertUnauthorized();
        $this->ask(['token' => $token], '')->assertUnauthorized();
    }

    public function test_a_users_own_token_is_not_a_service_token(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);

        $this->ask(['token' => $token], $token)->assertUnauthorized();
    }

    public function test_an_unconfigured_or_weak_service_token_refuses_everything(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);

        config(['security.socket.service_token' => '']);
        $this->ask(['token' => $token], '')->assertUnauthorized();
        $this->ask(['token' => $token], 'anything')->assertUnauthorized();

        config(['security.socket.service_token' => 'short']);
        $this->ask(['token' => $token], 'short')->assertUnauthorized();
    }

    public function test_a_caller_outside_the_allowed_addresses_is_refused_even_with_the_right_token(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);

        $this->ask(['token' => $token], self::SERVICE_TOKEN, ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();

        config(['security.socket.allowed_ips' => ['10.0.0.0/8']]);
        $this->ask(['token' => $token], self::SERVICE_TOKEN, ['REMOTE_ADDR' => '10.1.2.3'])->assertOk();

        config(['security.socket.allowed_ips' => []]);
        $this->ask(['token' => $token], self::SERVICE_TOKEN, ['REMOTE_ADDR' => '10.1.2.3'])->assertForbidden();
    }

    public function test_a_suspended_users_token_returns_401(): void
    {
        $driver = $this->driver();
        $token = $this->signIn($driver, AppClient::Driver);
        // Past the observer that ends the sessions: the token is still open when the socket asks.
        User::query()->whereKey($driver->id)->update(['status' => UserStatus::Suspended->value]);

        $this->ask(['token' => $token])->assertUnauthorized()->assertJsonMissingPath('user_id');
    }

    public function test_suspending_through_the_model_ends_the_session_and_the_token_returns_401(): void
    {
        $driver = $this->driver();
        $token = $this->signIn($driver, AppClient::Driver);
        $this->ask(['token' => $token])->assertOk();

        $driver->forceFill(['status' => UserStatus::Suspended])->save();

        $this->ask(['token' => $token])->assertUnauthorized();
    }

    public function test_deactivated_and_deleted_users_return_401(): void
    {
        $inactive = $this->driver();
        $inactiveToken = $this->signIn($inactive, AppClient::Driver);
        User::query()->whereKey($inactive->id)->update(['status' => UserStatus::Deactivated->value]);
        $this->ask(['token' => $inactiveToken])->assertUnauthorized();

        $gone = User::factory()->rider()->withPhone('+37491600003')->create();
        $goneToken = $this->signIn($gone, AppClient::Rider);
        $gone->delete();
        $this->ask(['token' => $goneToken])->assertUnauthorized();
    }

    public function test_a_revoked_token_returns_401(): void
    {
        $driver = $this->driver();
        $token = $this->signIn($driver, AppClient::Driver);
        $this->ask(['token' => $token])->assertOk();

        $driver->tokens()->delete();

        $this->ask(['token' => $token])->assertUnauthorized();
    }

    public function test_an_expired_token_returns_401(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);
        PersonalAccessToken::findToken($token)->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->ask(['token' => $token])->assertUnauthorized();
    }

    public function test_a_token_that_expires_while_the_clock_runs_stops_working(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);
        $this->ask(['token' => $token])->assertOk();

        $this->travel(config('taxikosmos.mobile_auth.access_ttl_minutes') + 1)->minutes();

        $this->ask(['token' => $token])->assertUnauthorized();
    }

    public function test_an_unknown_token_and_a_pending_phone_session_return_401(): void
    {
        $this->ask(['token' => '1|'.str_repeat('a', 40)])->assertUnauthorized();

        $noPhone = User::factory()->rider()->create();
        $this->ask(['token' => $this->signIn($noPhone, AppClient::Rider)])->assertUnauthorized();
    }

    public function test_an_admin_account_or_a_token_for_an_app_the_account_does_not_have_returns_401(): void
    {
        $admin = User::factory()->admin(AdminRole::Admin)->create();
        $this->ask(['token' => $admin->createToken('stolen', ['rider'])->plainTextToken])->assertUnauthorized();

        $rider = User::factory()->rider()->withPhone('+37491600004')->create();
        $this->ask(['token' => $rider->createToken('x', ['driver'])->plainTextToken])->assertUnauthorized();
        $this->ask(['token' => $rider->createToken('y', ['*'])->plainTextToken])->assertUnauthorized();
    }

    public function test_the_body_is_validated(): void
    {
        $this->ask([])->assertUnprocessable()->assertJsonValidationErrors(['token', 'ticket']);
        $this->ask(['token' => ['a']])->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->ask(['token' => str_repeat('a', 201)])->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->ask(['token' => 'a', 'ticket' => 'b'])->assertUnprocessable();
    }

    public function test_the_endpoint_is_rate_limited_per_calling_address_with_retry_after(): void
    {
        config(['security.rate_limits.socket_auth' => 3]);
        $this->travelTo(now()->startOfMinute());

        foreach (range(1, 3) as $_) {
            $this->ask(['token' => 'unknown'])->assertUnauthorized();
        }

        $this->ask(['token' => 'unknown'])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_the_endpoint_is_not_reachable_with_a_session_or_user_token_and_is_hidden_from_the_api_docs(): void
    {
        $route = app('router')->getRoutes()->getByName('internal.socket-auth');

        $this->assertContains('socket.service', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertStringContainsString('@hideFromAPIDocumentation', (new \ReflectionClass($route->getController()))->getDocComment());
    }

    public function test_an_admin_ticket_is_issued_to_a_signed_in_admin_and_exchanged_once(): void
    {
        $admin = User::factory()->admin(AdminRole::Support)->create();

        $issued = $this->actingAs($admin)->getJson('/admin/socket-ticket')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(60, $issued->json('expires_in'));
        $ticket = $issued->json('ticket');
        $this->assertSame(48, strlen($ticket));
        $this->assertNull(Cache::get('socket-ticket:'.$ticket), 'the ticket itself is never stored');

        $this->ask(['ticket' => $ticket])->assertOk()->assertExactJson([
            'user_id' => $admin->id, 'kind' => 'admin', 'app' => 'admin', 'modes' => ['admin'], 'roles' => ['support'], 'status' => 'active', 'expires_at' => null,
        ]);
        $this->ask(['ticket' => $ticket])->assertUnauthorized();
    }

    public function test_a_ticket_another_request_already_read_cannot_be_redeemed_again(): void
    {
        $admin = User::factory()->admin(AdminRole::Support)->create();
        $ticket = $this->actingAs($admin)->getJson('/admin/socket-ticket')->json('ticket');
        $this->ask(['ticket' => $ticket])->assertOk();

        // A racing request that read the ticket before it was deleted: the first redeemer's marker still refuses it.
        Cache::put('socket-ticket:'.hash('sha256', $ticket), $admin->id, 60);

        $this->ask(['ticket' => $ticket])->assertUnauthorized();
    }

    public function test_an_admin_ticket_expires(): void
    {
        $admin = User::factory()->admin(AdminRole::Support)->create();
        $ticket = $this->actingAs($admin)->getJson('/admin/socket-ticket')->json('ticket');

        $this->travel(61)->seconds();

        $this->ask(['ticket' => $ticket])->assertUnauthorized();
    }

    public function test_an_admin_who_lost_access_after_asking_cannot_redeem_the_ticket(): void
    {
        $admin = User::factory()->admin(AdminRole::Support)->create();
        $ticket = $this->actingAs($admin)->getJson('/admin/socket-ticket')->json('ticket');
        User::query()->whereKey($admin->id)->update(['status' => UserStatus::Suspended->value]);

        $this->ask(['ticket' => $ticket])->assertUnauthorized();
    }

    public function test_a_made_up_ticket_is_refused_and_a_ticket_needs_the_service_token_too(): void
    {
        $admin = User::factory()->admin(AdminRole::Support)->create();
        $ticket = $this->actingAs($admin)->getJson('/admin/socket-ticket')->json('ticket');

        $this->ask(['ticket' => str_repeat('x', 48)])->assertUnauthorized();
        $this->ask(['ticket' => $ticket], null)->assertUnauthorized();
        $this->ask(['ticket' => $ticket])->assertOk();
    }

    public function test_a_mobile_token_is_not_accepted_as_a_ticket_and_the_other_way_round(): void
    {
        $token = $this->signIn($this->driver(), AppClient::Driver);
        $admin = User::factory()->admin(AdminRole::Support)->create();
        $ticket = $this->actingAs($admin)->getJson('/admin/socket-ticket')->json('ticket');

        $this->ask(['ticket' => $token])->assertUnauthorized();
        $this->ask(['token' => $ticket])->assertUnauthorized();
    }

    public function test_the_ticket_route_refuses_guests_and_non_admins(): void
    {
        $this->getJson('/admin/socket-ticket')->assertUnauthorized();

        $rider = User::factory()->rider()->create();
        $this->actingAs($rider)->getJson('/admin/socket-ticket')->assertForbidden();
    }

    public function test_the_ticket_route_is_rate_limited_per_admin(): void
    {
        config(['security.rate_limits.socket_ticket' => 2]);
        $this->travelTo(now()->startOfMinute());
        $admin = User::factory()->admin(AdminRole::Support)->create();

        $this->actingAs($admin)->getJson('/admin/socket-ticket')->assertOk();
        $this->actingAs($admin)->getJson('/admin/socket-ticket')->assertOk();
        $this->actingAs($admin)->getJson('/admin/socket-ticket')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_the_contract_is_documented_next_to_the_stream_schemas(): void
    {
        $doc = file_get_contents(base_path('docs/realtime-events.md'));

        foreach (['POST /api/v1/internal/socket-auth', 'SOCKET_SERVICE_TOKEN', 'SOCKET_SERVICE_ALLOWED_IPS', 'GET /admin/socket-ticket', '`user_id`', '`modes`', '`status`', '401'] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }
        $this->assertLessThan(strpos($doc, '## Event catalogue'), strpos($doc, '## Authenticating a socket'));
    }
}
