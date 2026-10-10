<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use Tests\Feature\Admin\AdminTestCase;

class LoginRateLimitTest extends AdminTestCase
{
    private const PASSWORD = 'Corr3ct-Horse!Battery';

    private function attempt(string $email, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $email, 'password' => $password]);
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
}
