<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/v1 routes behind auth:sanctum: the caller must hold a real mobile access token (not
 * a cookie session), belong to an account that is still active, and not be an admin account.
 */
class EnsureMobileClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => __('Unauthenticated.')], 401);
        }

        if ($user->is_admin || ! $user->isActive()) {
            return response()->json(['message' => __('This account cannot use the apps.')], 403);
        }

        return $next($request);
    }
}
