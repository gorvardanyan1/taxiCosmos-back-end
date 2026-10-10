<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminAccessTest extends AdminTestCase
{
    /**
     * @return list<string>
     */
    private function adminGetUris(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin') && in_array('GET', $route->methods(), true))
            ->map(fn ($route) => '/'.str_replace(['{rider}', '{driver}', '{trip}'], ['80', '8', '4'], $route->uri()))
            ->values()
            ->all();
    }

    public function test_every_admin_route_is_behind_auth_admin_access_and_a_permission(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin'));
        $this->assertGreaterThanOrEqual(26, $routes->count());

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, "{$route->uri()} must require login.");
            $this->assertContains('admin', $middleware, "{$route->uri()} must require admin access.");

            $selfService = in_array($route->uri(), ['admin/account', 'admin/settings'], true);
            $hasPermission = collect($middleware)->contains(fn ($m) => str_starts_with($m, 'permission:'));
            $this->assertTrue($selfService || $hasPermission, "{$route->uri()} has no named permission.");
        }
    }

    public function test_guests_are_redirected_to_login_from_every_admin_page(): void
    {
        foreach ($this->adminGetUris() as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_riders_and_drivers_without_admin_access_get_403_everywhere(): void
    {
        $rider = User::factory()->rider()->driver()->create();

        foreach ($this->adminGetUris() as $uri) {
            $this->actingAs($rider)->get($uri)->assertForbidden();
        }
    }

    public function test_the_admin_flag_without_a_role_or_a_role_without_the_flag_is_refused(): void
    {
        $flagOnly = User::factory()->create(['is_admin' => true]);
        $roleOnly = User::factory()->create();
        $roleOnly->assignRole(AdminRole::SuperAdmin->value);

        $this->actingAs($flagOnly)->get('/admin')->assertForbidden();
        $this->actingAs($roleOnly)->get('/admin')->assertForbidden();
    }

    public function test_a_suspended_admin_is_refused(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $admin->forceFill(['status' => UserStatus::Suspended])->save();

        $this->actingAs($admin)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin/account')->assertForbidden();
    }

    /**
     * @return array<string, array{AdminRole, string, int}>
     */
    public static function rolePageMatrix(): array
    {
        return [
            'dispatcher sees live map' => [AdminRole::Dispatcher, '/admin/live-map', 200],
            'dispatcher cannot see transactions' => [AdminRole::Dispatcher, '/admin/transactions', 403],
            'dispatcher cannot see reports' => [AdminRole::Dispatcher, '/admin/reports', 403],
            'finance sees transactions' => [AdminRole::Finance, '/admin/transactions', 200],
            'finance sees driver balances' => [AdminRole::Finance, '/admin/driver-balances', 200],
            'finance cannot see live map' => [AdminRole::Finance, '/admin/live-map', 403],
            'finance cannot manage admins' => [AdminRole::Finance, '/admin/settings/users', 403],
            'support sees ratings' => [AdminRole::Support, '/admin/ratings', 200],
            'support sees tickets' => [AdminRole::Support, '/admin/support-tickets', 200],
            'support cannot see payouts' => [AdminRole::Support, '/admin/payouts', 403],
            'support cannot see zones' => [AdminRole::Support, '/admin/zones', 403],
            'admin sees gateways' => [AdminRole::Admin, '/admin/settings/gateways', 200],
            'admin cannot manage admins' => [AdminRole::Admin, '/admin/settings/users', 403],
            'every role sees own account' => [AdminRole::Dispatcher, '/admin/account', 200],
            'every role sees dashboard' => [AdminRole::Support, '/admin', 200],
            'super admin manages admins' => [AdminRole::SuperAdmin, '/admin/settings/users', 200],
        ];
    }

    #[DataProvider('rolePageMatrix')]
    public function test_named_permissions_decide_page_access_per_role(AdminRole $role, string $uri, int $status): void
    {
        $this->actingAs($this->admin($role))->get($uri)->assertStatus($status);
    }
}
