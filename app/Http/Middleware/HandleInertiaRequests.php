<?php

namespace App\Http\Middleware;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\User;
use App\Services\Admin\NavigationBadges;
use App\Support\AdminFixtures;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page: the admin, their permissions (for UI visibility only —
     * the server enforces), flash messages and app config. Never put secrets here.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $admin = $user instanceof User && $user->hasAdminAccess() ? $user : null;

        return [
            ...parent::share($request),
            'auth' => fn () => [
                'user' => $admin === null ? null : $this->presentUser($admin),
                'permissions' => $admin === null ? [] : $this->permissionsFor($admin),
            ],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'app' => fn () => [
                'name' => config('app.name'),
                'locale' => app()->getLocale(),
                'locales' => Locales::supported(),
                'timezone' => $admin?->timezone ?? config('taxikosmos.display_timezone'),
                'currencies' => config('taxikosmos.currencies'),
                'baseCurrency' => config('taxikosmos.base_currency'),
            ],
            // Badges are real counts (cached 60 s, only for pages the admin may open); notifications are still fixtures (P13-T6).
            'navigation' => fn () => $admin === null ? null : [
                ...app(AdminFixtures::class)->get('navigation'),
                'badges' => app(NavigationBadges::class)->forAdmin($admin),
                // The sidebar's Queue Monitor link: only for who the Horizon gate lets in.
                'queueMonitor' => Gate::forUser($admin)->allows('viewHorizon'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUser(User $user): array
    {
        $role = collect(AdminRole::cases())->first(fn (AdminRole $role) => $user->hasRole($role->value));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role?->value,
            'roleLabel' => $role?->label(),
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return AdminPermission::values();
        }

        return $user->getAllPermissions()->pluck('name')->sort()->values()->all();
    }
}
