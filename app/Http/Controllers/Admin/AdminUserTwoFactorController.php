<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetTwoFactorRequest;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use Illuminate\Http\RedirectResponse;

/**
 * Settings → Users: a super admin clears another admin's two-factor authentication (admins.manage).
 */
class AdminUserTwoFactorController extends Controller
{
    public function __construct(private readonly AdminTwoFactorService $twoFactor) {}

    public function reset(ResetTwoFactorRequest $request, int $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->twoFactor->reset($actor, User::query()->where('is_admin', true)->findOrFail($user), $request->validated('password'), $request->validated('reason'));

        return back()->with('success', __('Two-factor authentication was reset.'));
    }
}
