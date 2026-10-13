<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\PasswordConfirmationRequest;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two-factor setup screen. It lives outside /admin so that an admin whose role requires two-factor authentication
 * (and has not set it up) can reach it, and nothing else, after signing in (EnsureTwoFactorSetUp).
 */
class TwoFactorSetupController extends Controller
{
    public function __construct(private readonly AdminTwoFactorService $twoFactor) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->admin($request);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.account', ['tab' => 'security']);
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'setup' => $this->twoFactor->pendingSetup($user),
            'required' => $user->requiresTwoFactor(),
            'startUrl' => route('two-factor.setup.start', absolute: false),
            'submitUrl' => route('two-factor.setup.confirm', absolute: false),
        ]);
    }

    public function start(PasswordConfirmationRequest $request): RedirectResponse
    {
        $this->twoFactor->start($this->admin($request), $request->validated('password'));

        return redirect()->route('two-factor.setup');
    }

    public function confirm(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        $this->twoFactor->confirm($this->admin($request), $request->validated('code'));

        return redirect()->route('admin.account', ['tab' => 'security'])->with('success', __('Two-factor authentication is on.'));
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
