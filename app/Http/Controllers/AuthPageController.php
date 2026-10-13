<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET screens for admin authentication. Fortify runs with views disabled and handles the
 * POSTs (login, forgot/reset password). A page's `submitUrl` stays null until its POST route
 * exists (P3-T4 invitations). The two-factor setup screen is TwoFactorSetupController.
 */
class AuthPageController extends Controller
{
    public function login(Request $request): Response
    {
        return Inertia::render('Auth/Login', ['status' => $request->session()->get('status'), 'submitUrl' => route('login.store', absolute: false)]);
    }

    public function forgotPassword(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => $request->session()->get('status'), 'submitUrl' => route('password.email', absolute: false)]);
    }

    public function resetPassword(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'submitUrl' => route('password.update', absolute: false),
        ]);
    }

    public function acceptInvitation(string $token): Response
    {
        return Inertia::render('Auth/AcceptInvitation', ['token' => $token, 'invitation' => null, 'submitUrl' => null]);
    }

    /**
     * Only after the password step: Fortify keeps the account in the session (login.id) until the code is right.
     */
    public function twoFactorChallenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge', ['submitUrl' => route('two-factor.login.store', absolute: false)]);
    }
}
