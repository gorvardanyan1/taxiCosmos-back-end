<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P13-T2: for every role and every /admin route (pages and actions), the server answers 403 exactly where the role lacks the
 * route's permission, and never answers 403 where it holds it. The routes come from the router, so a new route is covered
 * the day it is added; the expectation comes from the route's own `permission:` middleware and the role matrix.
 */
class PermissionMatrixTest extends AdminTestCase
{
    /**
     * @return array<string, array{AdminRole}>
     */
    public static function roles(): array
    {
        return array_combine(AdminRole::values(), array_map(fn (AdminRole $role) => [$role], AdminRole::cases()));
    }

    /**
     * Every /admin route with the permissions it demands: each `permission:` middleware is an "any of" list, and all of them must pass.
     *
     * @return list<array{method: string, uri: string, rules: list<list<string>>, signed: bool}>
     */
    private function adminRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'admin')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $rules = [];
            foreach ($middleware as $name) {
                if (is_string($name) && str_starts_with($name, 'permission:')) {
                    $rules[] = explode('|', substr($name, strlen('permission:')));
                }
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [
                    'method' => $method,
                    // Every parameter is a numeric id here; a missing record is a 404, which is not a permission answer.
                    'uri' => '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()),
                    'rules' => $rules,
                    'signed' => in_array('signed:relative', $middleware, true),
                ];
            }
        }

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function held(AdminRole $role): array
    {
        return $role === AdminRole::SuperAdmin
            ? AdminPermission::values()
            : array_map(fn (AdminPermission $permission) => $permission->value, $role->defaultPermissions());
    }

    #[DataProvider('roles')]
    public function test_the_server_refuses_exactly_the_pages_and_actions_the_role_lacks(AdminRole $role): void
    {
        $admin = $this->admin($role);
        $held = $this->held($role);
        $denied = 0;
        $allowed = 0;

        foreach ($this->adminRoutes() as $route) {
            $permitted = collect($route['rules'])->every(fn (array $anyOf) => array_intersect($anyOf, $held) !== []);
            $status = $this->actingAs($admin)->call($route['method'], $route['uri'], [])->getStatusCode();
            $label = "{$role->value} {$route['method']} {$route['uri']} (needs ".json_encode($route['rules']).')';

            if (! $permitted) {
                $this->assertSame(403, $status, "{$label} must be refused");
                $denied++;
            } elseif (! $route['signed']) {
                // A signed link answers 403 on a bad signature, which says nothing about the permission.
                $this->assertNotSame(403, $status, "{$label} must not be refused");
                $allowed++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $allowed, 'the matrix must exercise permitted routes too');
        $this->assertSame($role === AdminRole::SuperAdmin, $denied === 0, "{$role->value}: only the super admin is refused nowhere");
    }

    public function test_the_matrix_covers_every_permission_a_route_uses_and_every_route_has_one_or_is_self_service(): void
    {
        $used = [];
        $selfService = [];

        foreach ($this->adminRoutes() as $route) {
            if ($route['rules'] === []) {
                $selfService[$route['uri']] = true;
            }
            foreach ($route['rules'] as $anyOf) {
                array_push($used, ...$anyOf);
            }
        }

        $this->assertEqualsCanonicalizing(['/admin/account', '/admin/account/locale', '/admin/account/profile', '/admin/account/password', '/admin/account/sessions', '/admin/account/two-factor', '/admin/account/two-factor/recovery-codes', '/admin/socket-ticket'], array_keys($selfService));
        $this->assertSame([], array_diff($used, AdminPermission::values()), 'a route names a permission that does not exist');
    }

    public function test_a_role_without_the_permission_is_refused_by_name_on_a_hidden_action_not_just_a_hidden_page(): void
    {
        // The buttons are hidden in the UI (Vitest); the same request sent by hand is refused all the same.
        $support = $this->admin(AdminRole::Support);
        $dispatcher = $this->admin(AdminRole::Dispatcher);
        $finance = $this->admin(AdminRole::Finance);

        $this->actingAs($dispatcher)->post('/admin/riders/1/suspend', ['reason' => 'x'])->assertForbidden();
        $this->actingAs($dispatcher)->patch('/admin/drivers/1', ['name' => 'X', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($support)->post('/admin/drivers/1/bank-accounts/1/reveal')->assertForbidden();
        $this->actingAs($finance)->post('/admin/riders/1/suspend', ['reason' => 'x'])->assertForbidden();
        $this->actingAs($finance)->post('/admin/drivers/1/documents/1/approve')->assertForbidden();
        $this->actingAs($support)->post('/admin/zones/1/fare-rules', [])->assertForbidden();
    }
}
