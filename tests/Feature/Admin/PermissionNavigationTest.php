<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminPermission;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * P13-T2: the browser hides what the server would refuse, so the two lists must not drift. The TypeScript permission list must
 * be the PHP enum, and every sidebar item must demand the permission of the route it links to.
 */
class PermissionNavigationTest extends AdminTestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_the_typescript_permission_list_is_the_php_enum(): void
    {
        $source = $this->source('resources/js/lib/permissions.ts');
        $this->assertSame(1, preg_match('/PERMISSIONS = \[(.*?)\] as const/s', $source, $list));
        preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $list[1], $found);

        $this->assertEqualsCanonicalizing(AdminPermission::values(), $found[1], 'resources/js/lib/permissions.ts and app/Enums/AdminPermission.php differ');
    }

    /**
     * @return list<array{key: string, href: string, permissions: list<string>}>
     */
    private function navigationItems(): array
    {
        $source = $this->source('resources/js/lib/navigation.tsx');
        $this->assertGreaterThan(0, preg_match_all("/key: '([^']+)'.*?href: '([^']+)'.*?permission: (\\[[^\\]]*\\]|'[^']*')/", $source, $matches, PREG_SET_ORDER));

        return array_map(function (array $m) {
            preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $m[3], $permissions);

            return ['key' => $m[1], 'href' => $m[2], 'permissions' => $permissions[1]];
        }, $matches);
    }

    public function test_every_sidebar_item_demands_what_its_route_demands(): void
    {
        $items = $this->navigationItems();
        $this->assertCount(17, $items, 'a sidebar item was added or removed: check it below');

        foreach ($items as $item) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn (LaravelRoute $route) => '/'.$route->uri() === $item['href'] && in_array('GET', $route->methods(), true));
            $this->assertNotNull($route, "{$item['key']} links to {$item['href']}, which is not a route");

            $required = collect($route->gatherMiddleware())->first(fn ($name) => is_string($name) && str_starts_with($name, 'permission:'));
            $this->assertNotNull($required, "{$item['href']} has no permission");
            $this->assertEqualsCanonicalizing(explode('|', substr($required, strlen('permission:'))), $item['permissions'], "{$item['key']}: the sidebar and the route disagree");
        }
    }

    public function test_no_two_sidebar_items_share_a_key_or_a_link(): void
    {
        $items = $this->navigationItems();

        $this->assertSame(count($items), count(array_unique(array_column($items, 'key'))));
        $this->assertSame(count($items), count(array_unique(array_column($items, 'href'))));
    }

    public function test_every_permission_a_page_checks_exists(): void
    {
        // can('x') / <Can permission="x"> / permission: 'x' in the pages and components: a typo would hide a button from everyone.
        $found = [];
        foreach (['resources/js/Pages', 'resources/js/Components'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory))) as $file) {
                if (! preg_match('/\.tsx$/', $file->getFilename()) || str_ends_with($file->getFilename(), '.test.tsx')) {
                    continue;
                }
                preg_match_all("/(?:can\\(|permission=\\{?|permission: )\\[?'([a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
                array_push($found, ...$m[1]);
            }
        }

        $this->assertNotEmpty($found);
        $this->assertSame([], array_values(array_diff(array_unique($found), AdminPermission::values())));
    }
}
