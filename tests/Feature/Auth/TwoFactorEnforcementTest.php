<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\User;
use App\Models\Zone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\InteractsWithTwoFactor;
use Tests\Support\ShowsConfig;

/**
 * P3-T2: super_admin and finance must have two-factor authentication. Until they have set it up they can reach the setup
 * screen and nothing else; the other admin roles are not forced.
 */
class TwoFactorEnforcementTest extends AdminTestCase
{
    use InteractsWithTwoFactor, ShowsConfig;

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.two_factor.required_roles' => ['super_admin', 'finance']]);
    }

    /**
     * @return array<string, array{AdminRole}>
     */
    public static function requiredRoles(): array
    {
        return ['super admin' => [AdminRole::SuperAdmin], 'finance' => [AdminRole::Finance]];
    }

    /**
     * @return array<string, array{AdminRole}>
     */
    public static function optionalRoles(): array
    {
        return ['admin' => [AdminRole::Admin], 'support' => [AdminRole::Support], 'dispatcher' => [AdminRole::Dispatcher]];
    }

    #[DataProvider('requiredRoles')]
    public function test_a_required_role_without_two_factor_is_sent_to_the_setup_screen_from_every_admin_page(AdminRole $role): void
    {
        $admin = $this->admin($role);

        foreach (['/admin', '/admin/riders', '/admin/drivers', '/admin/zones', '/admin/account', '/admin/activity-logs', '/admin/settings/users', '/horizon'] as $path) {
            $this->actingAs($admin)->get($path)->assertRedirect('/two-factor-setup');
        }
    }

    #[DataProvider('requiredRoles')]
    public function test_a_required_role_without_two_factor_cannot_write_anything(AdminRole $role): void
    {
        $admin = $this->admin($role);

        $this->actingAs($admin)->postJson('/admin/zones', ['name' => 'X'])->assertForbidden();
        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru'])->assertForbidden();
        $this->actingAs($admin)->getJson('/admin/riders')->assertForbidden();
        $this->assertSame(0, Zone::count());
        $this->assertNotSame('ru', $admin->fresh()->locale);
    }

    #[DataProvider('requiredRoles')]
    public function test_the_setup_screen_stays_open_and_says_the_role_requires_it(AdminRole $role): void
    {
        $this->actingAs($this->admin($role))->get('/two-factor-setup')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/TwoFactorSetup')->where('required', true));
    }

    public function test_a_half_finished_setup_does_not_count(): void
    {
        $admin = $this->admin(AdminRole::Finance, ['password' => 'Corr3ct-Horse!Battery']);
        $this->actingAs($admin)->post('/two-factor-setup', ['password' => 'Corr3ct-Horse!Battery'])->assertRedirect('/two-factor-setup');

        $this->actingAs($admin->fresh())->get('/admin')->assertRedirect('/two-factor-setup');
    }

    #[DataProvider('requiredRoles')]
    public function test_once_two_factor_is_on_the_whole_admin_opens_up(AdminRole $role): void
    {
        $admin = $this->admin($role);
        $this->enableTwoFactor($admin);

        $this->actingAs($admin->fresh())->get('/admin')->assertOk();
        $this->actingAs($admin->fresh())->get('/admin/account')->assertOk();
    }

    #[DataProvider('optionalRoles')]
    public function test_the_other_roles_are_not_forced(AdminRole $role): void
    {
        $admin = $this->admin($role);

        $this->actingAs($admin)->get('/admin/account')->assertOk();
    }

    public function test_an_admin_holding_one_required_role_among_others_is_forced(): void
    {
        $admin = $this->admin(AdminRole::Support);
        $admin->assignRole(AdminRole::Finance->value);

        $this->actingAs($admin)->get('/admin')->assertRedirect('/two-factor-setup');
    }

    public function test_signing_in_as_a_required_role_without_two_factor_lands_on_the_setup_screen(): void
    {
        $this->admin(AdminRole::Finance, ['email' => 'fin@x.test', 'password' => 'Corr3ct-Horse!Battery']);

        $this->post('/login', ['email' => 'fin@x.test', 'password' => 'Corr3ct-Horse!Battery'])->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/two-factor-setup');
    }

    public function test_signing_out_still_works_from_the_setup_screen(): void
    {
        $this->actingAs($this->admin(AdminRole::Finance))->post('/logout')->assertRedirect();

        $this->assertGuest();
    }

    public function test_the_rule_does_not_apply_to_riders_drivers_or_the_mobile_api(): void
    {
        $rider = User::factory()->rider()->create();
        $token = $rider->createToken('device', ['rider'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/trips/fare-estimate', [])->assertStatus(422);
    }

    public function test_the_default_list_is_super_admin_and_finance_everywhere_except_the_test_and_e2e_environments(): void
    {
        foreach (['production', 'staging', 'local'] as $environment) {
            $this->assertSame(['0' => 'super_admin', '1' => 'finance'], $this->configShow('security.two_factor.required_roles', ['APP_ENV' => $environment, 'ADMIN_2FA_REQUIRED_ROLES' => '']), $environment);
        }
        foreach (['testing', 'e2e'] as $environment) {
            $this->assertSame([], $this->configShow('security.two_factor.required_roles', ['APP_ENV' => $environment, 'ADMIN_2FA_REQUIRED_ROLES' => '']), $environment);
        }
    }

    public function test_a_blank_value_never_switches_the_rule_off_only_the_word_none_does(): void
    {
        foreach (['', '   '] as $blank) {
            $this->assertSame(['0' => 'super_admin', '1' => 'finance'], $this->configShow('security.two_factor.required_roles', ['APP_ENV' => 'production', 'ADMIN_2FA_REQUIRED_ROLES' => $blank]), "'{$blank}'");
        }
        foreach (['none', 'NONE', ' none '] as $off) {
            $this->assertSame([], $this->configShow('security.two_factor.required_roles', ['APP_ENV' => 'production', 'ADMIN_2FA_REQUIRED_ROLES' => $off]), $off);
        }
    }

    public function test_the_list_can_be_changed_or_emptied_in_env(): void
    {
        $this->assertSame(['0' => 'super_admin', '1' => 'finance', '2' => 'admin'], $this->configShow('security.two_factor.required_roles', ['APP_ENV' => 'production', 'ADMIN_2FA_REQUIRED_ROLES' => ' super_admin , finance,,admin ']));
        $this->assertSame(['0' => 'finance'], $this->configShow('security.two_factor.required_roles', ['APP_ENV' => 'testing', 'ADMIN_2FA_REQUIRED_ROLES' => 'finance']));
    }
}
