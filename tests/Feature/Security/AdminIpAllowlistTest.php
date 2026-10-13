<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use Illuminate\Http\Middleware\TrustProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\ShowsConfig;

/**
 * P9-T3: the optional ADMIN_IP_ALLOWLIST for /admin, /horizon and the admin sign-in screens. Off by default.
 */
class AdminIpAllowlistTest extends AdminTestCase
{
    use ShowsConfig;

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function fromIp(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'admin dashboard' => ['get', '/admin'],
            'admin page' => ['get', '/admin/drivers'],
            'admin write' => ['post', '/admin/drivers/1/suspend'],
            'unmatched admin url' => ['get', '/admin/no-such-page'],
            'horizon' => ['get', '/horizon'],
            'horizon api' => ['get', '/horizon/api/stats'],
            'sign-in page' => ['get', '/login'],
            'sign-in post' => ['post', '/login'],
            'forgot password page' => ['get', '/forgot-password'],
            'forgot password post' => ['post', '/forgot-password'],
            'reset password' => ['get', '/reset-password/some-token'],
            'reset password post' => ['post', '/reset-password'],
            'invitation' => ['get', '/accept-invitation/some-token'],
            'two-factor challenge' => ['get', '/two-factor-challenge'],
            'two-factor setup' => ['get', '/two-factor-setup'],
        ];
    }

    public function test_it_is_off_by_default(): void
    {
        $this->assertSame([], config('security.admin_ip_allowlist'));
        $this->assertSame([], $this->configShow('security.admin_ip_allowlist', ['ADMIN_IP_ALLOWLIST' => '']));

        $this->fromIp('198.51.100.9')->get('/login')->assertOk();
        $this->fromIp('198.51.100.9')->get('/admin')->assertRedirect('/login');
    }

    #[DataProvider('protectedRoutes')]
    public function test_an_address_outside_the_list_gets_403_on_every_protected_route(string $method, string $path): void
    {
        config(['security.admin_ip_allowlist' => ['203.0.113.7']]);

        $this->fromIp('198.51.100.9')->{$method}($path)->assertForbidden();
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_listed_address_is_not_blocked(string $method, string $path): void
    {
        config(['security.admin_ip_allowlist' => ['203.0.113.7']]);

        $this->assertNotSame(403, $this->fromIp('203.0.113.7')->{$method}($path)->getStatusCode(), "{$method} {$path} was blocked for a listed address");
    }

    public function test_a_blocked_guest_gets_403_not_a_redirect_to_the_sign_in_page(): void
    {
        config(['security.admin_ip_allowlist' => ['203.0.113.7']]);

        $this->fromIp('198.51.100.9')->get('/admin')->assertForbidden();
        $this->fromIp('203.0.113.7')->get('/admin')->assertRedirect('/login');
    }

    public function test_a_signed_in_admin_loses_access_from_an_address_outside_the_list(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        config(['security.admin_ip_allowlist' => ['203.0.113.0/24']]);

        $this->actingAs($admin)->fromIp('203.0.113.50')->get('/admin')->assertOk();
        $this->actingAs($admin)->fromIp('198.51.100.9')->get('/admin')->assertForbidden();
    }

    public function test_cidr_ranges_and_ipv6_addresses_are_understood(): void
    {
        config(['security.admin_ip_allowlist' => ['10.0.0.0/8', '2001:db8::/32', '203.0.113.7']]);

        $this->fromIp('10.20.30.40')->get('/login')->assertOk();
        $this->fromIp('11.0.0.1')->get('/login')->assertForbidden();
        $this->fromIp('2001:db8:abcd::1')->get('/login')->assertOk();
        $this->fromIp('2001:db9::1')->get('/login')->assertForbidden();
        $this->fromIp('203.0.113.8')->get('/login')->assertForbidden();
    }

    public function test_the_mobile_api_the_health_check_and_the_root_are_never_blocked(): void
    {
        config(['security.admin_ip_allowlist' => ['203.0.113.7']]);

        $this->fromIp('198.51.100.9')->postJson('/api/v1/auth/otp/verify', [])->assertStatus(422);
        $this->fromIp('198.51.100.9')->get('/up')->assertOk();
        $this->fromIp('198.51.100.9')->get('/')->assertRedirect('/admin');
    }

    public function test_the_refusal_still_carries_the_security_headers(): void
    {
        config(['security.admin_ip_allowlist' => ['203.0.113.7']]);

        $response = $this->fromIp('198.51.100.9')->get('/admin');

        $response->assertForbidden();
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_the_env_value_is_a_comma_separated_list_and_stray_spaces_are_ignored(): void
    {
        $this->assertSame(
            ['0' => '203.0.113.7', '1' => '10.0.0.0/8', '2' => '::1'],
            $this->configShow('security.admin_ip_allowlist', ['ADMIN_IP_ALLOWLIST' => ' 203.0.113.7 , 10.0.0.0/8,,::1']),
        );
    }

    public function test_the_address_is_the_forwarded_one_only_from_a_trusted_proxy(): void
    {
        config(['security.admin_ip_allowlist' => ['203.0.113.7']]);
        $forwarded = ['X-Forwarded-For' => '203.0.113.7'];

        // No trusted proxy configured (the default): a spoofed header changes nothing.
        $this->fromIp('198.51.100.9')->withHeaders($forwarded)->get('/login')->assertForbidden();

        // A proxy listed in TRUSTED_PROXIES: the client address is the one it reports.
        TrustProxies::at(['198.51.100.9']);
        $this->fromIp('198.51.100.9')->withHeaders($forwarded)->get('/login')->assertOk();
        $this->fromIp('198.51.100.9')->withHeaders(['X-Forwarded-For' => '192.0.2.1'])->get('/login')->assertForbidden();

        // A header from an address that is not a trusted proxy is still ignored.
        $this->fromIp('192.0.2.77')->withHeaders($forwarded)->get('/login')->assertForbidden();
    }
}
