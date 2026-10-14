<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePasswordRequest;
use App\Http\Requests\Auth\PasswordConfirmationRequest;
use App\Models\User;
use App\Services\Auth\AdminAccountService;
use Illuminate\Http\RedirectResponse;

/** My Account → Security: change the password, and sign out every other browser. Two-factor has its own controller. */
class AccountSecurityController extends Controller
{
    public function __construct(private readonly AdminAccountService $account) {}

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $this->account->changePassword($this->admin($request), $request->validated('current_password'), $request->validated('password'));

        return back()->with('success', __('Password updated. Your other sessions were signed out.'));
    }

    public function logoutOtherSessions(PasswordConfirmationRequest $request): RedirectResponse
    {
        $this->account->logoutOtherSessions($this->admin($request), $request->validated('password'));

        return back()->with('success', __('Other sessions were signed out.'));
    }

    private function admin(PasswordConfirmationRequest|UpdatePasswordRequest $request): User
    {
        /** @var User */
        return $request->user();
    }
}
