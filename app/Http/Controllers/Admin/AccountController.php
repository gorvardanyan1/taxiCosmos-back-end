<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public const TABS = ['profile', 'security', 'notifications'];

    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        return Inertia::render('Account/Show', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale ?? config('app.locale'),
                'timezone' => $user->timezone ?? config('taxikosmos.display_timezone'),
            ],
            ...$fixtures->get('account'),
            'actions' => [
                'updateProfile' => null, 'updatePassword' => null, 'regenerateRecoveryCodes' => null,
                'logoutOtherSessions' => null, 'updateNotifications' => null,
            ],
        ]);
    }
}
