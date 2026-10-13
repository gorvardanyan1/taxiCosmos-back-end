<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\InteractsWithTwoFactor;

/**
 * P3-T2: what an admin does with two-factor authentication once it exists (disable it, regenerate the recovery codes)
 * and what a super admin does for someone who lost their phone (reset). Every change asks for the password again.
 */
class TwoFactorManagementTest extends AdminTestCase
{
    use InteractsWithTwoFactor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Support, ['name' => 'Sam Support']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ownActions(): array
    {
        return ['disable' => ['delete', '/admin/account/two-factor'], 'regenerate' => ['post', '/admin/account/two-factor/recovery-codes']];
    }

    // ---- disable ----

    public function test_disabling_needs_the_password_and_clears_the_secret_and_the_codes(): void
    {
        $this->enableTwoFactor($this->admin);

        $this->actingAs($this->admin)->delete('/admin/account/two-factor', ['password' => 'wrong'])->assertSessionHasErrors(['password' => 'The password is incorrect.']);
        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication(), 'a wrong password changes nothing');

        $this->actingAs($this->admin)->from('/admin/account?tab=security')->delete('/admin/account/two-factor', ['password' => 'password'])
            ->assertRedirect('/admin/account?tab=security')->assertSessionHas('success', 'Two-factor authentication is off.');

        $fresh = $this->admin->fresh();
        $this->assertFalse($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);

        $entry = ActivityLogEntry::where('description', 'admin.two_factor.disabled')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame($this->admin->id, $entry->subject_id);
    }

    public function test_after_disabling_the_password_alone_signs_in_again(): void
    {
        $admin = $this->admin(AdminRole::Support, ['email' => 'sup@x.test', 'password' => 'Corr3ct-Horse!Battery']);
        $this->enableTwoFactor($admin);
        $this->actingAs($admin->fresh())->delete('/admin/account/two-factor', ['password' => 'Corr3ct-Horse!Battery']);
        $this->post('/logout');

        $this->post('/login', ['email' => 'sup@x.test', 'password' => 'Corr3ct-Horse!Battery'])->assertRedirect('/admin');

        $this->assertAuthenticated();
    }

    #[DataProvider('requiredRoles')]
    public function test_a_role_that_must_have_it_cannot_disable_it_not_even_with_the_right_password(AdminRole $role): void
    {
        config(['security.two_factor.required_roles' => ['super_admin', 'finance']]);
        $admin = $this->admin($role);
        $this->enableTwoFactor($admin);
        $admin->refresh();

        foreach (['password', 'wrong', ''] as $password) {
            $this->actingAs($admin)->deleteJson('/admin/account/two-factor', ['password' => $password])
                ->assertForbidden()->assertJson(['message' => 'Your role requires two-factor authentication, so it cannot be switched off.']);
        }
        $this->actingAs($admin)->delete('/admin/account/two-factor', ['password' => 'password'])->assertForbidden();

        $fresh = $admin->fresh();
        $this->assertTrue($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertNotNull($fresh->two_factor_secret);
        $this->assertSame(0, ActivityLogEntry::where('description', 'admin.two_factor.disabled')->count());
    }

    #[DataProvider('requiredRoles')]
    public function test_the_service_itself_refuses_to_disable_it_for_a_role_that_must_have_it(AdminRole $role): void
    {
        config(['security.two_factor.required_roles' => ['super_admin', 'finance']]);
        $admin = $this->admin($role);
        $this->enableTwoFactor($admin);

        try {
            app(AdminTwoFactorService::class)->disable($admin->fresh(), 'password');
            $this->fail('A role that must have two-factor authentication cannot switch it off.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Your role requires two-factor authentication, so it cannot be switched off.', $e->getMessage());
        }

        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    /**
     * @return array<string, array{AdminRole}>
     */
    public static function requiredRoles(): array
    {
        return ['super admin' => [AdminRole::SuperAdmin], 'finance' => [AdminRole::Finance]];
    }

    public function test_an_unfinished_setup_can_be_cancelled_by_a_role_that_may_disable(): void
    {
        $this->actingAs($this->admin)->post('/two-factor-setup', ['password' => 'password']);
        $this->admin->refresh();
        $this->assertTrue($this->admin->hasPendingTwoFactorSetup());

        $this->actingAs($this->admin)->delete('/admin/account/two-factor', ['password' => 'password'])->assertRedirect();

        $this->assertNull($this->admin->fresh()->two_factor_secret);
        $this->assertSame(1, ActivityLogEntry::where('description', 'admin.two_factor.setup_cancelled')->count());
    }

    public function test_disabling_when_there_is_nothing_to_disable_is_a_conflict(): void
    {
        $this->actingAs($this->admin)->deleteJson('/admin/account/two-factor', ['password' => 'password'])
            ->assertStatus(409)->assertJson(['message' => 'Two-factor authentication is not on for this account.']);
    }

    // ---- recovery codes ----

    public function test_regenerating_needs_the_password_and_gives_eight_new_codes_once(): void
    {
        $old = $this->enableTwoFactor($this->admin)['codes'];
        $this->admin->refresh();

        $this->actingAs($this->admin)->post('/admin/account/two-factor/recovery-codes', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame($old, $this->admin->fresh()->recoveryCodes(), 'a wrong password changes nothing');

        $this->actingAs($this->admin)->from('/admin/account?tab=security')->post('/admin/account/two-factor/recovery-codes', ['password' => 'password'])
            ->assertRedirect('/admin/account?tab=security')->assertSessionHas('success');

        $new = $this->admin->fresh()->recoveryCodes();
        $this->assertCount(8, $new);
        $this->assertCount(8, array_unique($new));
        $this->assertSame([], array_intersect($old, $new), 'the old set is gone entirely');

        // The new codes are shown once, on the page the admin returns to, and not on the next visit.
        $this->actingAs($this->admin->fresh())->withSession(['two_factor_new_recovery_codes' => $new])->get('/admin/account?tab=security')
            ->assertInertia(fn (Assert $page) => $page->where('new_recovery_codes', $new));
        $this->actingAs($this->admin->fresh())->get('/admin/account?tab=security')->assertInertia(fn (Assert $page) => $page->where('new_recovery_codes', null));
    }

    public function test_the_flash_that_carries_the_new_codes_comes_with_the_redirect_only(): void
    {
        $this->enableTwoFactor($this->admin);

        $response = $this->actingAs($this->admin->fresh())->post('/admin/account/two-factor/recovery-codes', ['password' => 'password']);

        $this->assertSame($this->admin->fresh()->recoveryCodes(), session('two_factor_new_recovery_codes'));
        $response->assertSessionMissing('two_factor_new_recovery_codes_stale');
    }

    public function test_the_old_recovery_codes_stop_working_and_the_new_ones_work(): void
    {
        $admin = $this->admin(AdminRole::Support, ['email' => 'sup@x.test', 'password' => 'Corr3ct-Horse!Battery']);
        $old = $this->enableTwoFactor($admin)['codes'];
        $this->actingAs($admin->fresh())->post('/admin/account/two-factor/recovery-codes', ['password' => 'Corr3ct-Horse!Battery']);
        $new = $admin->fresh()->recoveryCodes();
        $this->post('/logout');

        $this->post('/login', ['email' => 'sup@x.test', 'password' => 'Corr3ct-Horse!Battery']);
        $this->post('/two-factor-challenge', ['recovery_code' => $old[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => $new[0]])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_regenerating_is_audited_without_any_code_and_needs_two_factor_to_be_on(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/account/two-factor/recovery-codes', ['password' => 'password'])->assertStatus(409);

        $this->enableTwoFactor($this->admin);
        $this->actingAs($this->admin->fresh())->post('/admin/account/two-factor/recovery-codes', ['password' => 'password']);

        $entry = ActivityLogEntry::where('description', 'admin.two_factor.recovery_codes_regenerated')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame(['recovery_codes' => 8], $entry->properties['context']);
        foreach ($this->admin->fresh()->recoveryCodes() as $code) {
            $this->assertStringNotContainsString($code, json_encode($entry->properties));
        }
    }

    #[DataProvider('ownActions')]
    public function test_the_password_guesses_are_limited_per_admin(string $method, string $url): void
    {
        $this->enableTwoFactor($this->admin);
        $this->admin->refresh();
        $this->travelTo(now()->startOfMinute());

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)->{$method}($url, ['password' => 'wrong'])->assertSessionHasErrors('password');
        }

        $limited = $this->actingAs($this->admin)->{$method}($url, ['password' => 'password']);
        $limited->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication(), 'a throttled request changes nothing');
    }

    #[DataProvider('ownActions')]
    public function test_a_guest_is_sent_to_sign_in_and_a_rider_has_no_access(string $method, string $url): void
    {
        $this->{$method}($url, ['password' => 'password'])->assertRedirect('/login');

        $rider = User::factory()->rider()->create();
        $this->actingAs($rider)->{$method}($url, ['password' => 'password'])->assertRedirect('/login');
    }

    #[DataProvider('ownActions')]
    public function test_the_password_is_required(string $method, string $url): void
    {
        $this->enableTwoFactor($this->admin);

        $this->actingAs($this->admin->fresh())->{$method}($url, [])->assertSessionHasErrors('password');
        $this->actingAs($this->admin->fresh())->{$method}($url, ['password' => str_repeat('a', 300)])->assertSessionHasErrors('password');
    }

    // ---- Fortify's own management routes ----

    public function test_fortifys_own_management_routes_are_closed(): void
    {
        $this->enableTwoFactor($this->admin);
        $this->admin->refresh();
        $secret = $this->secretOf($this->admin);

        foreach ([['post', '/user/two-factor-authentication'], ['delete', '/user/two-factor-authentication'], ['post', '/user/confirmed-two-factor-authentication'],
            ['get', '/user/two-factor-qr-code'], ['get', '/user/two-factor-secret-key'], ['get', '/user/two-factor-recovery-codes'], ['post', '/user/two-factor-recovery-codes']] as [$method, $url]) {
            $response = $this->actingAs($this->admin)->{$method}($url, ['password' => 'password']);
            $response->assertNotFound();
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame($secret, $this->secretOf($this->admin));
    }

    // ---- My Account ----

    public function test_my_account_shows_the_real_state_and_only_the_actions_that_apply(): void
    {
        $this->actingAs($this->admin)->get('/admin/account?tab=security')->assertInertia(fn (Assert $page) => $page
            ->where('two_factor_enabled', false)->where('two_factor_required', false)->where('recovery_codes_left', 0)->where('new_recovery_codes', null)
            ->where('actions.enableTwoFactor', '/two-factor-setup')->where('actions.disableTwoFactor', null)->where('actions.regenerateRecoveryCodes', null));

        $this->enableTwoFactor($this->admin);
        $this->actingAs($this->admin->fresh())->get('/admin/account?tab=security')->assertInertia(fn (Assert $page) => $page
            ->where('two_factor_enabled', true)->where('recovery_codes_left', 8)
            ->where('actions.enableTwoFactor', null)->where('actions.disableTwoFactor', '/admin/account/two-factor')->where('actions.regenerateRecoveryCodes', '/admin/account/two-factor/recovery-codes'));
    }

    public function test_a_role_that_must_have_it_is_not_offered_the_disable_action(): void
    {
        config(['security.two_factor.required_roles' => ['finance']]);
        $finance = $this->admin(AdminRole::Finance);
        $this->enableTwoFactor($finance);

        $this->actingAs($finance->fresh())->get('/admin/account?tab=security')->assertInertia(fn (Assert $page) => $page
            ->where('two_factor_required', true)->where('actions.disableTwoFactor', null)->where('actions.regenerateRecoveryCodes', '/admin/account/two-factor/recovery-codes'));
    }

    public function test_the_recovery_codes_left_count_goes_down_as_codes_are_used(): void
    {
        $codes = $this->enableTwoFactor($this->admin)['codes'];
        $this->admin->fresh()->replaceRecoveryCode($codes[0]);
        $this->admin->fresh()->replaceRecoveryCode($codes[1]);

        $this->actingAs($this->admin->fresh())->get('/admin/account?tab=security')->assertInertia(fn (Assert $page) => $page->where('recovery_codes_left', 6));
    }

    // ---- reset by a super admin ----

    private function superAdmin(): User
    {
        return $this->admin(AdminRole::SuperAdmin, ['name' => 'Sue Super']);
    }

    private function reset(User $actor, User $target, array $body = ['password' => 'password', 'reason' => 'Lost phone'])
    {
        return $this->actingAs($actor)->post("/admin/settings/users/{$target->id}/two-factor/reset", $body);
    }

    public function test_a_super_admin_resets_another_admins_two_factor_with_a_reason_and_it_is_logged(): void
    {
        $super = $this->superAdmin();
        $this->enableTwoFactor($this->admin);

        $this->reset($super, $this->admin)->assertRedirect()->assertSessionHas('success', 'Two-factor authentication was reset.');

        $fresh = $this->admin->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);

        $entry = ActivityLogEntry::where('description', 'admin.two_factor.reset')->sole();
        $this->assertSame($super->id, $entry->causer_id);
        $this->assertSame($this->admin->id, $entry->subject_id);
        $this->assertSame('Lost phone', $entry->properties['reason']);
        $this->assertSame('Sue Super', $entry->properties['actor_name']);
    }

    public function test_a_reset_admin_signs_in_with_the_password_alone_again(): void
    {
        $target = $this->admin(AdminRole::Support, ['email' => 'sup@x.test', 'password' => 'Corr3ct-Horse!Battery']);
        $this->enableTwoFactor($target);
        $this->reset($this->superAdmin(), $target);
        $this->post('/logout');

        $this->post('/login', ['email' => 'sup@x.test', 'password' => 'Corr3ct-Horse!Battery'])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($target);
    }

    public function test_a_reset_admin_whose_role_requires_it_must_set_it_up_again(): void
    {
        config(['security.two_factor.required_roles' => ['finance']]);
        $finance = $this->admin(AdminRole::Finance);
        $this->enableTwoFactor($finance);
        $this->actingAs($finance->fresh())->get('/admin/account')->assertOk();

        $this->reset($this->superAdmin(), $finance);

        $this->actingAs($finance->fresh())->get('/admin/account')->assertRedirect('/two-factor-setup');
    }

    public function test_the_reset_needs_a_reason_and_the_super_admins_own_password(): void
    {
        $super = $this->superAdmin();
        $this->enableTwoFactor($this->admin);

        $this->reset($super, $this->admin, ['password' => 'password', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->reset($super, $this->admin, ['password' => 'password', 'reason' => '   '])->assertSessionHasErrors('reason');
        $this->reset($super, $this->admin, ['password' => 'password', 'reason' => str_repeat('r', 501)])->assertSessionHasErrors('reason');
        $this->reset($super, $this->admin, ['password' => 'wrong', 'reason' => 'Lost phone'])->assertSessionHasErrors(['password' => 'The password is incorrect.']);
        $this->reset($super, $this->admin, ['reason' => 'Lost phone'])->assertSessionHasErrors('password');

        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication(), 'nothing was reset');
        $this->assertSame(0, ActivityLogEntry::where('description', 'admin.two_factor.reset')->count());
    }

    public function test_only_a_super_admin_can_reset_it(): void
    {
        $this->enableTwoFactor($this->admin);

        foreach ([AdminRole::Admin, AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->reset($this->admin($role), $this->admin)->assertForbidden();
        }

        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_guest_cannot_reset_anything(): void
    {
        $this->enableTwoFactor($this->admin);

        $this->post("/admin/settings/users/{$this->admin->id}/two-factor/reset", ['password' => 'password', 'reason' => 'x'])->assertRedirect('/login');

        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_role_holding_the_permission_without_being_a_super_admin_is_still_refused(): void
    {
        $this->enableTwoFactor($this->admin);
        $delegate = $this->admin(AdminRole::Admin);
        $delegate->givePermissionTo('admins.manage');

        $this->reset($delegate, $this->admin)->assertForbidden();

        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_super_admin_cannot_reset_their_own_here_nor_something_that_is_not_there_nor_a_non_admin(): void
    {
        $super = $this->superAdmin();
        $this->enableTwoFactor($super);

        $this->actingAs($super->fresh())->postJson("/admin/settings/users/{$super->id}/two-factor/reset", ['password' => 'password', 'reason' => 'x'])
            ->assertStatus(409)->assertJson(['message' => 'You cannot reset your own two-factor authentication here. Use My Account.']);
        $this->assertTrue($super->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($super)->postJson("/admin/settings/users/{$this->admin->id}/two-factor/reset", ['password' => 'password', 'reason' => 'x'])
            ->assertStatus(409)->assertJson(['message' => 'Two-factor authentication is not on for this account.']);

        $rider = User::factory()->rider()->create();
        $this->actingAs($super)->postJson("/admin/settings/users/{$rider->id}/two-factor/reset", ['password' => 'password', 'reason' => 'x'])->assertNotFound();
        $this->actingAs($super)->postJson('/admin/settings/users/99999999/two-factor/reset', ['password' => 'password', 'reason' => 'x'])->assertNotFound();
        $this->actingAs($super)->postJson('/admin/settings/users/99999999999999999999/two-factor/reset', ['password' => 'password', 'reason' => 'x'])->assertNotFound();
    }

    public function test_the_reset_password_guesses_are_limited(): void
    {
        $super = $this->superAdmin();
        $this->enableTwoFactor($this->admin);
        $this->travelTo(now()->startOfMinute());

        for ($i = 0; $i < 5; $i++) {
            $this->reset($super, $this->admin, ['password' => 'wrong', 'reason' => 'x'])->assertSessionHasErrors('password');
        }

        $limited = $this->reset($super, $this->admin);
        $limited->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertTrue($this->admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_the_users_page_says_who_has_two_factor_and_offers_the_reset_to_a_super_admin_only(): void
    {
        $super = $this->superAdmin();
        $this->enableTwoFactor($this->admin);

        $this->actingAs($super)->get('/admin/settings/users')->assertInertia(fn (Assert $page) => $page
            ->where('actions.resetTwoFactor', '/admin/settings/users/{id}/two-factor/reset')
            ->where('users.data', fn ($rows) => collect($rows)->firstWhere('id', $this->admin->id)['two_factor_enabled'] === true
                && collect($rows)->firstWhere('id', $super->id)['two_factor_enabled'] === false));

        $this->actingAs($this->admin(AdminRole::Admin))->get('/admin/settings/users')->assertForbidden();
    }
}
