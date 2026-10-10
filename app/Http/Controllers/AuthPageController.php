<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET screens for admin authentication. Fortify runs with views disabled and handles the
 * POSTs (login, forgot/reset password). A page's `submitUrl` stays null until its POST route
 * exists (P3-T2 two-factor, P3-T4 invitations).
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

    public function twoFactorChallenge(): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge', ['submitUrl' => null]);
    }

    public function twoFactorSetup(): Response
    {
        return Inertia::render('Auth/TwoFactorSetup', ['setup' => null, 'submitUrl' => null]);
    }
}
