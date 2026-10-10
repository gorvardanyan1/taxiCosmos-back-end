<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

class SharedPropsTest extends AdminTestCase
{
    public function test_auth_user_and_role_label_are_shared(): void
    {
        $admin = $this->admin(AdminRole::Finance, ['name' => 'Morgan Webb', 'email' => 'morgan@taxikosmos.test']);

        $this->actingAs($admin)->get('/admin/transactions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', ['id' => $admin->id, 'name' => 'Morgan Webb', 'email' => 'morgan@taxikosmos.test', 'role' => 'finance', 'roleLabel' => 'Finance']));
    }

    public function test_permissions_are_exactly_the_roles_matrix(): void
    {
        $expected = array_map(fn (AdminPermission $p) => $p->value, AdminRole::Finance->defaultPermissions());
        sort($expected);

        $this->actingAs($this->admin(AdminRole::Finance))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', $expected));
    }

    public function test_super_admin_gets_every_permission(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', AdminPermission::values()));
    }

    public function test_flash_messages_are_shared_once_after_a_redirect(): void
    {
        Route::middleware('web')->get('/_test/flash', fn () => redirect('/admin')->with('success', 'Rider suspended.')->with('error', 'Gateway timeout.'));
        $admin = $this->admin();

        $this->actingAs($admin)->followingRedirects()->get('/_test/flash')
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('flash', ['success' => 'Rider suspended.', 'error' => 'Gateway timeout.']));

        // Flash data lives for one request only.
        $this->actingAs($admin)->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('flash', ['success' => null, 'error' => null]));
    }

    public function test_app_config_is_shared_and_the_admins_timezone_wins(): void
    {
        $this->actingAs($this->admin(attributes: ['timezone' => 'Europe/Berlin']))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.name', config('app.name'))
                ->where('app.locale', 'en')
                ->where('app.timezone', 'Europe/Berlin')
                ->where('app.baseCurrency', 'AMD')
                ->where('app.currencies', config('taxikosmos.currencies'))
                ->where('navigation.badges.drivers', 63));

        $this->actingAs($this->admin(attributes: ['timezone' => null]))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('app.timezone', config('taxikosmos.display_timezone')));
    }

    public function test_guests_get_no_user_permissions_or_navigation(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('auth', ['user' => null, 'permissions' => []])
            ->where('navigation', null)
            ->where('app.baseCurrency', 'AMD'));
    }

    public function test_no_secret_or_key_material_reaches_the_page(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/settings/gateways')->getContent();

        $this->assertStringNotContainsString((string) config('app.key'), $html);
        $this->assertStringNotContainsString((string) config('app.blind_index_key'), $html);
        $this->assertStringNotContainsString('password', strtolower(json_encode(User::query()->first()->toArray())));
    }
}
