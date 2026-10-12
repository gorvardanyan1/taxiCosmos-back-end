<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\ShowsConfig;

/**
 * P9-T3: the session cookie is Secure, HttpOnly and SameSite=Lax in staging and production.
 */
class SessionCookieTest extends AdminTestCase
{
    use ShowsConfig;

    public function test_staging_and_production_default_to_secure_http_only_lax_cookies(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $session = $this->configShow('session', ['APP_ENV' => $environment]);

            $this->assertSame('true', $session['secure'], "{$environment}: secure");
            $this->assertSame('true', $session['http_only'], "{$environment}: http_only");
            $this->assertSame('lax', $session['same_site'], "{$environment}: same_site");
        }
    }

    public function test_local_development_over_plain_http_is_not_forced_to_secure(): void
    {
        foreach (['local', 'e2e'] as $environment) {
            $session = $this->configShow('session', ['APP_ENV' => $environment]);

            $this->assertSame('false', $session['secure'], $environment);
            $this->assertSame('true', $session['http_only'], $environment);
            $this->assertSame('lax', $session['same_site'], $environment);
        }
    }

    public function test_a_blank_or_invalid_setting_does_not_switch_secure_cookies_off_in_production(): void
    {
        foreach (['', 'maybe'] as $value) {
            $this->assertSame('true', $this->configShow('session', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => $value])['secure'], "value '{$value}'");
        }
    }

    public function test_an_explicit_setting_overrides_the_environment_default(): void
    {
        $this->assertSame('false', $this->configShow('session', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
        $this->assertSame('true', $this->configShow('session', ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'])['secure']);
    }

    public function test_the_cookies_the_browser_receives_carry_the_flags(): void
    {
        config(['session.secure' => true]);
        $this->admin(AdminRole::Admin, ['email' => 'a@x.test', 'password' => 'Corr3ct-Horse!Battery']);

        $response = $this->post('https://localhost/login', ['email' => 'a@x.test', 'password' => 'Corr3ct-Horse!Battery']);

        $response->assertRedirect();
        $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $cookie) => $cookie->getName());

        $session = $cookies[config('session.cookie')];
        $this->assertTrue($session->isSecure(), 'session cookie: Secure');
        $this->assertTrue($session->isHttpOnly(), 'session cookie: HttpOnly');
        $this->assertSame('lax', $session->getSameSite(), 'session cookie: SameSite');

        // The CSRF cookie is read by the page's JavaScript, so it cannot be HttpOnly; it is still Secure and Lax.
        $xsrf = $cookies['XSRF-TOKEN'];
        $this->assertTrue($xsrf->isSecure());
        $this->assertFalse($xsrf->isHttpOnly());
        $this->assertSame('lax', $xsrf->getSameSite());
    }
}
