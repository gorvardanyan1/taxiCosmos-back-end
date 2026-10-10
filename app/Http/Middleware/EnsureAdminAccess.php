<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate: the session user must have admin access (is_admin flag, an admin
 * role and an active account). Route-level permission middleware then checks the action.
 */
class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasAdminAccess(), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
