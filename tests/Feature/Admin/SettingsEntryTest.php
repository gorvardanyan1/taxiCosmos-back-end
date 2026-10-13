<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;

/**
 * P13-T2: Settings opens the first tab the admin may use, so the sidebar link never lands on a 403.
 */
class SettingsEntryTest extends AdminTestCase
{
    public function test_a_super_admin_lands_on_users_and_roles(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin))->get('/admin/settings')->assertRedirect('/admin/settings/users');
    }

    public function test_an_admin_without_admins_manage_lands_on_the_first_tab_they_can_open(): void
    {
        $admin = $this->admin(AdminRole::Admin);

        $this->actingAs($admin)->get('/admin/settings')->assertRedirect('/admin/settings/gateways');
        $this->actingAs($admin)->get('/admin/settings/gateways')->assertOk();
        $this->actingAs($admin)->get('/admin/settings/users')->assertForbidden();
    }

    public function test_roles_with_neither_settings_permission_get_403(): void
    {
        foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->actingAs($this->admin($role))->get('/admin/settings')->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
    }
}
