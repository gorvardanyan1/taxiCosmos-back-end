<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin whose role must have two-factor authentication (config security.two_factor.required_roles) and who has not
 * finished the setup can reach nothing but the setup screen: pages redirect to it, writes are refused (403).
 * Used on /admin and /horizon after the sign-in has been checked.
 */
class EnsureTwoFactorSetUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresTwoFactor() && ! $user->hasEnabledTwoFactorAuthentication()) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->route('two-factor.setup');
            }

            abort(Response::HTTP_FORBIDDEN, __('Set up two-factor authentication first.'));
        }

        return $next($request);
    }
}
