<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Admin\AdminTestCase;

/**
 * P9-T3: the general limiters (admin, admin-auth, api, payment) and the shape of every rate-limited
 * response: 429 with Retry-After. The specific limiters have their own tests (login, OTP, fare estimate, reveal).
 */
class RateLimitResponsesTest extends AdminTestCase
{
    private function assertThrottled($response): void
    {
        $response->assertStatus(429);
        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter, 'Retry-After missing');
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);
    }

    private function fromIp(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_every_admin_page_and_action_is_limited_per_signed_in_admin(): void
    {
        config(['security.rate_limits.admin' => 3]);
        $one = $this->admin(AdminRole::SuperAdmin);
        $other = $this->admin(AdminRole::SuperAdmin);
        $this->travelTo(now()->startOfMinute());

        $this->actingAs($one)->get('/admin')->assertOk();
        $this->actingAs($one)->get('/admin/riders')->assertOk();
        $this->actingAs($one)->post('/admin/drivers/999999/suspend', ['reason' => 'x'])->assertNotFound();

        $this->assertThrottled($this->actingAs($one)->get('/admin'));
        $this->assertThrottled($this->actingAs($one)->get('/admin/riders'));
        $this->assertThrottled($this->actingAs($one)->post('/admin/drivers/999999/suspend', ['reason' => 'x']));

        // Another admin, even from the same address, has their own budget.
        $this->actingAs($other)->get('/admin')->assertOk();

        $this->travel(61)->seconds();
        $this->actingAs($one)->get('/admin')->assertOk();
    }

    public function test_the_default_admin_ceiling_is_generous_enough_for_normal_work(): void
    {
        $this->assertSame(300, config('security.rate_limits.admin'));
        $this->assertSame(120, config('security.rate_limits.api'));
        $this->assertSame(10, config('security.rate_limits.payment'));
    }

    public function test_the_admin_auth_endpoints_are_limited_per_address_and_endpoint(): void
    {
        config(['security.rate_limits.admin_auth' => 3]);
        $this->travelTo(now()->startOfMinute());

        for ($i = 0; $i < 3; $i++) {
            $this->fromIp('10.0.0.1')->post('/forgot-password', ['email' => "n{$i}@x.test"])->assertRedirect();
        }

        $this->assertThrottled($this->fromIp('10.0.0.1')->post('/forgot-password', ['email' => 'n9@x.test']));
        $this->assertThrottled($this->fromIp('10.0.0.1')->postJson('/forgot-password', ['email' => 'n9@x.test']));

        // Another address, and another endpoint from the same address, have their own budget.
        $this->fromIp('10.0.0.2')->post('/forgot-password', ['email' => 'n9@x.test'])->assertRedirect();
        $this->assertNotSame(429, $this->fromIp('10.0.0.1')->post('/reset-password', ['email' => 'n9@x.test'])->getStatusCode());

        $this->travel(61)->seconds();
        $this->fromIp('10.0.0.1')->post('/forgot-password', ['email' => 'n9@x.test'])->assertRedirect();
    }

    public function test_every_endpoint_fortify_registers_goes_through_the_admin_auth_limiter(): void
    {
        $fortify = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->getActionName(), 'Laravel\\Fortify\\'));

        $this->assertNotEmpty($fortify);
        foreach ($fortify as $route) {
            $this->assertContains('throttle:admin-auth', $route->gatherMiddleware(), implode('|', $route->methods()).' '.$route->uri());
        }
    }

    public function test_the_sign_in_post_keeps_its_own_limiter_and_is_not_counted_twice(): void
    {
        config(['security.rate_limits.admin_auth' => 1]);

        // admin-auth would block the second request; POST /login is left to the login limiter (5 per minute per email + IP).
        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('10.0.0.1')->withHeaders(['X-Inertia' => 'true'])->post('/login', ['email' => 'a@x.test', 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }
    }

    public function test_authenticated_mobile_calls_are_limited_per_user_not_per_address(): void
    {
        config(['security.rate_limits.api' => 2]);
        $rider = User::factory()->rider()->create();
        $other = User::factory()->rider()->create();
        $token = $rider->createToken('device', ['rider'])->plainTextToken;
        $otherToken = $other->createToken('device', ['rider'])->plainTextToken;
        // The test client keeps the first request's guard (and user) alive, so forget it between callers.
        $call = function (string $token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->postJson('/api/v1/trips/fare-estimate', []);
        };
        $this->travelTo(now()->startOfMinute());

        $call($token)->assertStatus(422);
        $call($token)->assertStatus(422);

        $limited = $call($token);
        $this->assertThrottled($limited);
        $limited->assertJsonStructure(['message']);

        // Same address (a carrier NAT), different rider: unaffected.
        $call($otherToken)->assertStatus(422);

        $this->travel(61)->seconds();
        $call($token)->assertStatus(422);
    }

    public function test_the_mobile_logout_endpoints_share_the_authenticated_ceiling(): void
    {
        config(['security.rate_limits.api' => 2]);
        $token = User::factory()->rider()->create()->createToken('device', ['rider'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/trips/fare-estimate', [])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/trips/fare-estimate', [])->assertStatus(422);

        $this->assertThrottled($this->withToken($token)->postJson('/api/v1/auth/logout'));
    }

    public function test_the_public_auth_endpoints_keep_their_specific_limiters(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/auth/') && in_array($route->uri(), ['api/v1/auth/otp/request', 'api/v1/auth/otp/verify', 'api/v1/auth/refresh', 'api/v1/auth/social/{provider}']));

        $this->assertCount(4, $routes);
        foreach ($routes as $route) {
            $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $route->uri().' has no throttle');
        }
    }

    public function test_the_payment_limiter_stops_a_user_after_the_configured_attempts(): void
    {
        config(['security.rate_limits.payment' => 2]);
        Route::middleware(['api', 'throttle:payment'])->post('/api/v1/_payment-probe', fn () => response()->json(['ok' => true]));
        $this->travelTo(now()->startOfMinute());

        $this->postJson('/api/v1/_payment-probe')->assertOk();
        $this->postJson('/api/v1/_payment-probe')->assertOk();
        $this->assertThrottled($this->postJson('/api/v1/_payment-probe'));
    }

    public function test_the_html_429_page_keeps_retry_after(): void
    {
        config(['security.rate_limits.admin' => 1, 'app.debug' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $response = $this->actingAs($admin)->get('/admin');

        $this->assertThrottled($response);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Errors/Show')->where('status', 429));
    }
}
