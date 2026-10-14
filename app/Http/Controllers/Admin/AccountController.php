<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\AdminAccountService;
use App\Support\AdminFixtures;
use App\Support\Locales;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public const TABS = ['profile', 'security', 'notifications'];

    public function __invoke(Request $request, AdminFixtures $fixtures, AdminAccountService $account): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        return Inertia::render('Account/Show', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => Locales::forUser($user) ?? app()->getLocale(),
                'timezone' => $user->timezone ?? config('taxikosmos.display_timezone'),
            ],
            ...$fixtures->get('account'),
            'sessions' => $account->sessions($user, $request),
            // False when sessions cannot be listed (not in the database): the list then shows only this browser, though others may exist.
            'sessions_listed' => config('session.driver') === 'database',
            // Real two-factor state and sessions; the notification preferences above are still fixtures (P13-T6).
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'two_factor_required' => $user->requiresTwoFactor(),
            'recovery_codes_left' => $user->hasEnabledTwoFactorAuthentication() ? $user->recoveryCodesLeft() : 0,
            // Shown once, right after they were generated, then gone with the flash.
            'new_recovery_codes' => $request->session()->get(AccountTwoFactorController::NEW_CODES_KEY),
            'locales' => array_map(fn (string $code) => ['code' => $code, 'name' => Locales::NAMES[$code] ?? $code], Locales::supported()),
            'actions' => [
                'updateProfile' => route('admin.account.profile', absolute: false), 'updatePassword' => route('admin.account.password', absolute: false),
                'logoutOtherSessions' => route('admin.account.sessions.destroy', absolute: false), 'updateNotifications' => null,
                'enableTwoFactor' => $user->hasEnabledTwoFactorAuthentication() ? null : route('two-factor.setup.start', absolute: false),
                'disableTwoFactor' => $user->hasEnabledTwoFactorAuthentication() && ! $user->requiresTwoFactor() ? route('admin.account.two-factor.destroy', absolute: false) : null,
                'regenerateRecoveryCodes' => $user->hasEnabledTwoFactorAuthentication() ? route('admin.account.two-factor.recovery-codes', absolute: false) : null,
                'updateLocale' => route('admin.account.locale', absolute: false),
            ],
        ]);
    }
}
