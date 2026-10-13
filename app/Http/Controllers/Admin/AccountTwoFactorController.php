<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\PasswordConfirmationRequest;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use Illuminate\Http\RedirectResponse;

/**
 * My Account → Security: switch two-factor authentication off and regenerate the recovery codes. Setting it up is the
 * TwoFactorSetupController (it is also the screen an admin who must have it is sent to).
 */
class AccountTwoFactorController extends Controller
{
    /** Session key the new recovery codes travel in, once, to the Account page. */
    public const NEW_CODES_KEY = 'two_factor_new_recovery_codes';

    public function __construct(private readonly AdminTwoFactorService $twoFactor) {}

    public function destroy(DisableTwoFactorRequest $request): RedirectResponse
    {
        $this->twoFactor->disable($this->admin($request), $request->validated('password'));

        return back()->with('success', __('Two-factor authentication is off.'));
    }

    public function regenerateRecoveryCodes(PasswordConfirmationRequest $request): RedirectResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes($this->admin($request), $request->validated('password'));

        return back()->with(self::NEW_CODES_KEY, $codes)->with('success', __('New recovery codes generated. The old ones no longer work.'));
    }

    private function admin(PasswordConfirmationRequest $request): User
    {
        /** @var User */
        return $request->user();
    }
}
