<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs an admin out after config('taxikosmos.admin.idle_timeout_minutes') without a request
 * and sends them to /login with a message (Inertia follows the redirect).
 */
class EnforceAdminIdleTimeout
{
    public const SESSION_KEY = 'admin_last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            return $next($request);
        }

        $timeoutSeconds = config('taxikosmos.admin.idle_timeout_minutes') * 60;
        $lastActivity = $request->session()->get(self::SESSION_KEY);
        $now = now()->getTimestamp();

        if (is_int($lastActivity) && $now - $lastActivity > $timeoutSeconds) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your session expired after '.config('taxikosmos.admin.idle_timeout_minutes').' minutes of inactivity. Please sign in again.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED)
                : redirect()->route('login')->with('status', $message);
        }

        $request->session()->put(self::SESSION_KEY, $now);

        return $next($request);
    }
}
