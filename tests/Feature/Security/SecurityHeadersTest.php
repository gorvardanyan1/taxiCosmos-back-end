<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Admin\AdminTestCase;

/**
 * P9-T3: every response of the admin web surface carries the hardening headers, whatever it is:
 * a page, a redirect, a refusal, an error page, an unmatched URL.
 */
class SecurityHeadersTest extends AdminTestCase
{
    private const FRAME = 'DENY';

    private const PERMISSIONS = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()';

    private function assertHardened($response, bool $csp = true): void
    {
        $this->assertSame(self::FRAME, $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame(self::PERMISSIONS, $response->headers->get('Permissions-Policy'));

        if ($csp) {
            $this->assertNotNull($response->headers->get('Content-Security-Policy'), 'CSP missing');
        }
    }

    public function test_an_admin_page_has_every_security_header(): void
    {
        $response = $this->actingAs($this->admin(AdminRole::SuperAdmin))->get('/admin');

        $response->assertOk();
        $this->assertHardened($response);
    }

    public function test_the_content_security_policy_allows_only_the_app_and_its_fonts_and_forbids_framing(): void
    {
        $csp = $this->actingAs($this->admin())->get('/admin')->headers->get('Content-Security-Policy');
        $directives = collect(explode('; ', $csp))->mapWithKeys(fn ($d) => [strtok($d, ' ') => substr($d, strlen(strtok($d, ' ')) + 1)]);

        $this->assertSame("'self'", $directives['default-src']);
        $this->assertSame("'self'", $directives['script-src'], 'no inline or eval scripts, no third-party scripts');
        $this->assertSame("'self' 'unsafe-inline' https://fonts.googleapis.com", $directives['style-src']);
        $this->assertSame("'self' data: https://fonts.gstatic.com", $directives['font-src']);
        $this->assertSame("'self'", $directives['connect-src']);
        $this->assertSame("'none'", $directives['frame-ancestors']);
        $this->assertSame("'none'", $directives['object-src']);
        $this->assertSame("'self'", $directives['base-uri']);
        $this->assertSame("'self'", $directives['form-action']);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_the_sign_in_screens_and_guest_redirects_have_the_headers_too(): void
    {
        foreach (['/login', '/forgot-password', '/reset-password/some-token', '/two-factor-challenge'] as $path) {
            $this->assertHardened($this->get($path));
        }

        $redirect = $this->get('/admin/drivers');
        $redirect->assertRedirect('/login');
        $this->assertHardened($redirect);
    }

    public function test_refusals_errors_and_unmatched_urls_have_the_headers_too(): void
    {
        $dispatcher = $this->admin(AdminRole::Dispatcher);

        // 403: a signed-in admin without the permission.
        $forbidden = $this->actingAs($dispatcher)->get('/admin/activity-logs');
        $forbidden->assertForbidden();
        $this->assertHardened($forbidden);

        // 404: a record that does not exist, and a URL that matches no route at all (never reaches the web group).
        $this->assertHardened($this->actingAs($dispatcher)->get('/admin/drivers/999999')->assertNotFound());
        $this->assertHardened($this->get('/admin/no-such-page')->assertNotFound());
        $this->assertHardened($this->get('/no-such-page')->assertNotFound());

        // 405: a wrong verb.
        $this->assertHardened($this->actingAs($dispatcher)->delete('/admin/drivers/1'));
    }

    public function test_writes_have_the_headers_too(): void
    {
        $support = $this->admin(AdminRole::Support);

        $this->assertHardened($this->actingAs($support)->post('/admin/drivers/999999/suspend', ['reason' => 'x']));
    }

    public function test_a_throttled_response_has_the_headers_and_retry_after(): void
    {
        config(['security.rate_limits.admin' => 1]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $limited = $this->actingAs($admin)->get('/admin');

        $limited->assertStatus(429)->assertHeader('Retry-After');
        $this->assertHardened($limited);
    }

    public function test_hsts_is_sent_over_https_only_and_can_be_turned_off(): void
    {
        $this->assertNull($this->get('http://localhost/login')->headers->get('Strict-Transport-Security'), 'plain HTTP: HSTS means nothing');

        $this->assertSame('max-age=31536000; includeSubDomains', $this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));
        $this->assertSame('max-age=31536000; includeSubDomains', $this->actingAs($this->admin())->get('https://localhost/admin')->headers->get('Strict-Transport-Security'));

        config(['security.headers.hsts_max_age' => 600]);
        $this->assertSame('max-age=600; includeSubDomains', $this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));

        config(['security.headers.hsts_max_age' => 0]);
        $this->assertNull($this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_report_only_mode_sends_the_policy_under_the_report_only_name(): void
    {
        config(['security.headers.csp_report_only' => true]);

        $response = $this->get('/login');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertHardened($response, csp: false);
    }

    public function test_horizon_gets_every_header_except_the_policy_its_inline_scripts_would_break(): void
    {
        $response = $this->get('/horizon');

        $this->assertHardened($response, csp: false);
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_vite_dev_server_is_allowed_only_while_it_runs(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, "http://localhost:5173\n");
        app(Vite::class)->useHotFile($hot);

        $csp = $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' http:\/\/localhost:5173 'nonce-[A-Za-z0-9+\/=]{16,}';/", $csp);
        $this->assertStringContainsString("connect-src 'self' http://localhost:5173 ws://localhost:5173;", $csp);

        unlink($hot);
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringNotContainsString('nonce-', $csp);
    }

    public function test_the_inline_react_refresh_preamble_of_npm_run_dev_is_allowed_by_a_nonce_never_unsafe_inline(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, "http://localhost:5173\n");
        app(Vite::class)->useHotFile($hot);

        $response = $this->get('/login');
        unlink($hot);

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $policy);
        $this->assertNotEmpty($policy, 'the policy carries a nonce');
        preg_match('/script-src ([^;]+)/', $response->headers->get('Content-Security-Policy'), $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc[1], 'no unsafe-inline for scripts');

        // Every script tag the page renders is either loaded from the dev server (allowed by origin) or carries that nonce.
        preg_match_all('/<script\b([^>]*)>/', $response->getContent(), $tags);
        $inline = array_filter($tags[1], fn (string $attributes) => ! str_contains($attributes, ' src=') && ! str_starts_with(trim($attributes), 'src='));
        $this->assertNotEmpty($inline, 'hot mode renders the inline preamble');
        foreach ($inline as $attributes) {
            if (str_contains($attributes, 'type="module"')) {
                $this->assertStringContainsString('nonce="'.$policy[1].'"', $attributes, 'inline module script without the policy nonce');
            }
        }
    }

    public function test_every_response_gets_its_own_nonce(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, "http://localhost:5173\n");
        app(Vite::class)->useHotFile($hot);

        $first = $this->get('/login')->headers->get('Content-Security-Policy');
        $second = $this->get('/login')->headers->get('Content-Security-Policy');
        unlink($hot);

        $this->assertNotSame($first, $second);
    }

    public function test_a_header_the_route_sets_itself_is_not_overwritten(): void
    {
        Route::get('/admin/_own-headers', fn () => response('ok', 200, [
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]))->middleware('web');

        $response = $this->get('/admin/_own-headers');

        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'the headers it did not set still arrive');
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_mobile_api_is_left_alone(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/verify', []);

        foreach (['X-Frame-Options', 'Content-Security-Policy', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security'] as $header) {
            $this->assertNull($response->headers->get($header), $header);
        }
    }

    public function test_every_admin_route_is_covered(): void
    {
        // A route added later cannot opt out: the headers do not depend on the route's own middleware.
        Route::get('/admin/_probe', fn () => 'ok')->middleware('web');
        $admin = User::factory()->admin(AdminRole::SuperAdmin)->create();

        $this->assertHardened($this->actingAs($admin)->get('/admin/_probe'));
    }
}
