<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/v1 routes behind auth:sanctum: the caller must hold a real mobile access token (not
 * a cookie session), belong to an account that is still active, and not be an admin account.
 *
 * A refusal carries a machine-readable `code` so the apps can show a blocking screen (P3-T4): admin_account,
 * account_suspended, account_deactivated or account_pending_deletion. A blocked (not active) account may still read its
 * own status (GET /me), sign out and write to support; everything else is refused.
 */
class EnsureMobileClient
{
    /** Route names a blocked account may still call. */
    private const ALLOWED_WHEN_BLOCKED = ['me.show', 'auth.logout', 'auth.logout-all', 'support.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => __('Unauthenticated.')], 401);
        }

        if ($user->is_admin) {
            return response()->json(['message' => __('This account cannot use the apps.'), 'code' => 'admin_account'], 403);
        }

        if (! $user->isActive() && ! $request->routeIs(self::ALLOWED_WHEN_BLOCKED)) {
            return response()->json([
                'message' => $user->status === UserStatus::Suspended ? __('This account is suspended.') : __('This account is not available.'),
                'code' => 'account_'.$user->status->value,
            ], 403);
        }

        return $next($request);
    }
}
