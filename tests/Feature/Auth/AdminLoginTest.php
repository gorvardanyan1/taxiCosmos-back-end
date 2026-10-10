<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Feature\Admin\AdminTestCase;

class AdminLoginTest extends AdminTestCase
{
    private const PASSWORD = 'Corr3ct-Horse!Battery';

    /** Headers an Inertia form submission sends. */
    private const INERTIA = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];

    private function adminWithPassword(AdminRole $role = AdminRole::Finance, array $attributes = []): User
    {
        return $this->admin($role, ['email' => 'morgan@taxikosmos.test', 'password' => self::PASSWORD, ...$attributes]);
    }

    private function attempt(string $email, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(self::INERTIA)
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function test_an_admin_signs_in_through_the_inertia_form_and_is_redirected_not_given_json(): void
    {
        $admin = $this->adminWithPassword();
        $this->travelTo(now()->startOfMinute());

        $response = $this->attempt('morgan@taxikosmos.test', self::PASSWORD);

        $response->assertRedirect('/admin');
        $this->assertStringNotContainsString('json', (string) $response->headers->get('Content-Type'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(now()->getTimestamp(), $admin->fresh()->last_login_at->getTimestamp());
        $this->assertSame(now()->getTimestamp(), session(EnforceAdminIdleTimeout::SESSION_KEY));
    }

    public function test_the_email_is_case_insensitive(): void
    {
        $admin = $this->adminWithPassword();

        $this->attempt('  MORGAN@TaxiKosmos.test', self::PASSWORD)->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_password_fails_with_a_generic_message(): void
    {
        $this->adminWithPassword();

        $this->attempt('morgan@taxikosmos.test', 'wrong-password')
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_riders_drivers_and_inactive_admins_cannot_sign_in_and_get_the_same_message(): void
    {
        $candidates = [
            'rider' => User::factory()->rider()->create(['email' => 'rider@x.test', 'password' => self::PASSWORD]),
            'driver' => User::factory()->driver()->create(['email' => 'driver@x.test', 'password' => self::PASSWORD]),
            'flag without role' => User::factory()->create(['email' => 'flag@x.test', 'password' => self::PASSWORD, 'is_admin' => true]),
            'suspended admin' => $this->admin(AdminRole::Admin, ['email' => 'susp@x.test', 'password' => self::PASSWORD, 'status' => UserStatus::Suspended]),
            'deactivated admin' => $this->admin(AdminRole::Admin, ['email' => 'deact@x.test', 'password' => self::PASSWORD, 'status' => UserStatus::Deactivated]),
            'unknown' => null,
        ];
        $deleted = $this->admin(AdminRole::Admin, ['email' => 'gone@x.test', 'password' => self::PASSWORD]);
        $deleted->delete();
        $candidates['soft-deleted admin'] = $deleted;
        $role = User::factory()->create(['email' => 'roleonly@x.test', 'password' => self::PASSWORD]);
        $role->assignRole(AdminRole::SuperAdmin->value);
        $candidates['role without flag'] = $role;

        foreach ($candidates as $label => $user) {
            $email = $user?->email ?? 'nobody@x.test';
            $this->attempt($email, self::PASSWORD, '10.0.1.'.crc32($label) % 250)
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
            $this->assertGuest();
        }
    }

    public function test_an_otp_only_account_without_a_password_cannot_sign_in(): void
    {
        $admin = $this->admin(AdminRole::Admin, ['email' => 'nopass@x.test']);
        $admin->forceFill(['password' => null])->save();

        $this->attempt('nopass@x.test', '')->assertSessionHasErrors('password');
        $this->attempt('nopass@x.test', 'anything')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_the_session_is_regenerated_on_login(): void
    {
        $this->adminWithPassword();
        $this->get('/login');
        $before = session()->getId();
        session()->put('pre_login_marker', 'kept-or-not');

        $this->attempt('morgan@taxikosmos.test', self::PASSWORD)->assertRedirect('/admin');

        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_invalidates_the_session_and_returns_to_login(): void
    {
        $admin = $this->adminWithPassword();
        $this->attempt('morgan@taxikosmos.test', self::PASSWORD);
        session()->put('secret_marker', 'value');
        $sessionId = session()->getId();
        $token = session()->token();

        $this->withHeaders(self::INERTIA)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull(session('secret_marker'));
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->get('/admin')->assertRedirect('/login');
        $this->assertNotNull($admin->fresh());
    }

    public function test_logout_needs_a_signed_in_user_and_login_needs_a_guest(): void
    {
        $this->post('/logout')->assertRedirect('/login');

        $admin = $this->adminWithPassword();
        $this->actingAs($admin)->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_admin_guard_only_resolves_admin_flagged_users(): void
    {
        $rider = User::factory()->rider()->create(['email' => 'rider@x.test']);
        $admin = $this->adminWithPassword();

        $provider = Auth::guard('web')->getProvider();
        $this->assertNull($provider->retrieveById($rider->id));
        $this->assertTrue($provider->retrieveById($admin->id)->is($admin));
        $this->assertNull(Password::broker('admins')->getUser(['email' => 'rider@x.test']));
        $this->assertNotNull(Password::broker('admins')->getUser(['email' => $admin->email]));
    }

    public function test_unauthenticated_admin_visits_redirect_to_the_login_screen(): void
    {
        $this->get('/admin/riders')->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('submitUrl', '/login'));
    }

    public function test_remember_me_is_never_honoured_so_it_cannot_outlive_the_idle_timeout(): void
    {
        $admin = $this->adminWithPassword();
        $tokenBefore = $admin->getRememberToken();
        $this->travelTo(now()->startOfMinute());

        $login = $this->withHeaders(self::INERTIA)->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'remember' => '1']);

        $login->assertRedirect('/admin');
        $recallers = collect($login->headers->getCookies())->filter(fn (Cookie $cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertCount(0, $recallers, 'No remember cookie may be issued for an admin session.');
        $this->assertSame($tokenBefore, $admin->fresh()->getRememberToken(), 'Signing in must not mint a remember token.');
    }

    public function test_after_the_server_session_expires_the_admin_must_sign_in_again_even_if_remember_was_ticked(): void
    {
        $admin = $this->adminWithPassword();
        $this->travelTo(now()->startOfMinute());
        $login = $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'remember' => '1']);
        $cookies = collect($login->headers->getCookies());

        $this->travel(3)->hours();
        $this->flushSession();
        Auth::forgetGuards();

        $request = $this;
        foreach ($cookies->filter(fn (Cookie $cookie) => str_starts_with($cookie->getName(), 'remember_web_')) as $cookie) {
            $request = $request->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        $request->get('/admin')->assertRedirect('/login');
        $this->assertGuest();
    }
}
