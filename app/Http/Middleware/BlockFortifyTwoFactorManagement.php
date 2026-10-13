<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify registers its own two-factor management routes (/user/two-factor-authentication, the QR code, the
 * recovery codes, ...) as soon as the feature is on. They know nothing about our rules (password confirmation,
 * roles that cannot switch it off, the audit log), so they answer 404. The sign-in challenge is not affected.
 */
class BlockFortifyTwoFactorManagement
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->is('user/two-factor-*', 'user/confirmed-two-factor-authentication'), 404);

        return $next($request);
    }
}
