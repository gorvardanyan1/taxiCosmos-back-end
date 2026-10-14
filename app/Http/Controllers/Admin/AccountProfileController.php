<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\AdminAccountService;
use Illuminate\Http\RedirectResponse;

/** My Account → Profile: the signed-in admin's own name and timezone. */
class AccountProfileController extends Controller
{
    public function __invoke(UpdateProfileRequest $request, AdminAccountService $account): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $account->updateProfile($admin, $request->validated('name'), $request->validated('timezone'));

        return back()->with('success', __('Profile updated.'));
    }
}
