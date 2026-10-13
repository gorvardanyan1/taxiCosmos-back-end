<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use Tests\Feature\Admin\AdminTestCase;

class LoginRateLimitTest extends AdminTestCase
{
    private const PASSWORD = 'Corr3ct-Horse!Battery';

    /**
     * The sign-in form is an Inertia page, so by default this posts like the browser does.
     */
    private function attempt(string $email, string $password, string $ip = '10.0.0.1', bool $inertia = true)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($inertia ? ['X-Inertia' => 'true'] : [])
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function test_the_sixth_attempt_in_a_minute_is_blocked_even_with_the_right_password(): void
    {
        $this->admin(AdminRole::Admin, ['email' => 'a@x.test', 'password' => self::PASSWORD]);
        $this->travelTo(now()->startOfMinute());

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('a@x.test', 'wrong')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $response = $this->attempt('a@x.test', self::PASSWORD);

        $response->assertRedirect()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_the_route_throttle_also_counts_successful_logins(): void
    {
        $admin = $this->admin(AdminRole::Admin, ['email' => 'a@x.test', 'password' => self::PASSWORD]);
        $this->travelTo(now()->startOfMinute());

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('a@x.test', self::PASSWORD)->assertRedirect('/admin');
            $this->post('/logout');
        }

        $this->attempt('a@x.test', self::PASSWORD)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotNull($admin->fresh());
    }

    public function test_the_limit_is_per_email_and_ip_and_resets_after_a_minute(): void
    {
        $this->admin(AdminRole::Admin, ['email' => 'a@x.test', 'password' => self::PASSWORD]);
        $this->admin(AdminRole::Admin, ['email' => 'b@x.test', 'password' => self::PASSWORD]);
        $this->travelTo(now()->startOfMinute());

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('a@x.test', 'wrong');
        }

        // Same email from another IP, and another email from the same IP, are not blocked.
        $this->attempt('a@x.test', self::PASSWORD, '10.0.0.2')->assertRedirect('/admin');
        $this->post('/logout');
        $this->attempt('b@x.test', self::PASSWORD)->assertRedirect('/admin');
        $this->post('/logout');

        // The blocked pair is still blocked, then allowed again a minute later.
        $this->attempt('A@X.TEST', self::PASSWORD)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->attempt('a@x.test', self::PASSWORD)->assertRedirect('/admin');
    }

    public function test_the_sign_in_page_gets_the_message_and_a_retry_after_header(): void
    {
        $this->admin(AdminRole::Admin, ['email' => 'a@x.test', 'password' => self::PASSWORD]);
        $this->travelTo(now()->startOfMinute());

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('a@x.test', 'wrong');
        }

        $response = $this->attempt('a@x.test', self::PASSWORD);

        $response->assertRedirect()->assertSessionHasErrors('email');
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame('Too many login attempts. Please try again in 60 seconds.', session('errors')->first('email'));

        $this->travel(20)->seconds();
        $this->assertSame('40', $this->attempt('a@x.test', self::PASSWORD)->headers->get('Retry-After'), 'the header counts down');
    }

    public function test_any_other_client_gets_a_429_with_retry_after_and_is_not_signed_in(): void
    {
        $this->admin(AdminRole::Admin, ['email' => 'a@x.test', 'password' => self::PASSWORD]);
        $this->travelTo(now()->startOfMinute());

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('a@x.test', 'wrong', inertia: false)->assertSessionHasErrors('email');
        }

        $json = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/login', ['email' => 'a@x.test', 'password' => self::PASSWORD]);
        $json->assertStatus(429)->assertJson(['message' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertSame('60', $json->headers->get('Retry-After'));

        $form = $this->attempt('a@x.test', self::PASSWORD, inertia: false);
        $form->assertStatus(429);
        $this->assertSame('60', $form->headers->get('Retry-After'));
        $this->assertGuest();
    }

    public function test_one_address_cannot_try_many_accounts_past_the_per_ip_ceiling(): void
    {
        config(['security.rate_limits.login_ip' => 6]);
        $this->travelTo(now()->startOfMinute());

        // Six different emails from one address are each under the per-email limit...
        for ($i = 1; $i <= 6; $i++) {
            $this->attempt("user{$i}@x.test", 'wrong')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        // ...but the seventh account is refused, and another address is not affected.
        $blocked = $this->attempt('user7@x.test', 'wrong');
        $blocked->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->attempt('user7@x.test', 'wrong', '10.0.0.9')->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->travel(61)->seconds();
        $this->attempt('user7@x.test', 'wrong')->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }
}
