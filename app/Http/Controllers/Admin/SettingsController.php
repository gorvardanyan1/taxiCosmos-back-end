<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class SettingsController extends Controller
{
    public function __construct(private readonly AdminFixtures $fixtures) {}

    /**
     * Admin users and the live role → permission matrix (invite/edit/deactivate: P3-T4).
     */
    public function users(Request $request): Response
    {
        $users = User::query()
            ->where('is_admin', true)
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate(config('taxikosmos.admin.per_page_options')[0])
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values(),
                'status' => $user->status->value,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]);

        $roles = Role::query()->whereIn('name', AdminRole::values())->with('permissions:id,name')->get();

        return Inertia::render('Settings/Users', [
            'users' => $users,
            'roles' => array_map(fn (AdminRole $role) => ['name' => $role->value, 'label' => $role->label()], AdminRole::cases()),
            'matrix' => $roles->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->values()]),
            'actions' => ['invite' => null, 'remove' => null],
        ]);
    }

    public function gateways(): Response
    {
        return Inertia::render('Settings/Gateways', [
            'gateways' => $this->fixtures->get('settings')['gateways'],
            'actions' => ['replaceKey' => null, 'testConnection' => null],
        ]);
    }

    public function currencies(): Response
    {
        $active = array_map(fn (array $currency) => [...$currency, 'active' => true], config('taxikosmos.currencies'));
        $inactive = array_map(fn (array $currency) => [...$currency, 'active' => false], $this->fixtures->get('settings')['inactive_currencies']);

        return Inertia::render('Settings/Currencies', [
            'currencies' => [...$active, ...$inactive],
            'baseCurrency' => config('taxikosmos.base_currency'),
            'actions' => ['update' => null],
        ]);
    }

    public function platform(): Response
    {
        return Inertia::render('Settings/Platform', [
            'settings' => $this->fixtures->get('settings')['platform'],
            'actions' => ['save' => null],
        ]);
    }

    public function maps(): Response
    {
        return Inertia::render('Settings/Maps', [
            'maps' => $this->fixtures->get('settings')['maps'],
            'actions' => ['save' => null, 'testConnection' => null],
        ]);
    }
}
