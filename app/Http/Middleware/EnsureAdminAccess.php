<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate: the session user must still have admin access (is_admin flag, an admin
 * role and an active account). Anyone who lost it — deactivated, suspended, role removed —
 * is signed out on this request and sent to /login. Route-level permission middleware then
 * checks the specific action.
 */
class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasAdminAccess()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('login')->with('status', 'Your admin access is no longer active. Contact a super admin if you think this is a mistake.');
    }
}
