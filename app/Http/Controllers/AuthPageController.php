<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET screens for admin authentication, named like Fortify's routes so P3-T1 can enable
 * Fortify with views disabled and keep these pages. Each page's `submitUrl` stays null
 * until the matching POST route exists (P3-T1 login/reset, P3-T2 2FA, P3-T4 invitations).
 */
class AuthPageController extends Controller
{
    public function login(Request $request): Response
    {
        return Inertia::render('Auth/Login', ['status' => $request->session()->get('status'), 'submitUrl' => null]);
    }

    public function forgotPassword(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => $request->session()->get('status'), 'submitUrl' => null]);
    }

    public function resetPassword(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'submitUrl' => null,
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
