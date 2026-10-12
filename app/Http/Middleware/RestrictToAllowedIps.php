<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional network gate for the admin web surface: when ADMIN_IP_ALLOWLIST is set, /admin, /horizon and
 * the admin sign-in screens answer 403 to any other address. Off by default (empty list). Global middleware,
 * so it also covers URLs that match no route and runs before authentication.
 *
 * The address is $request->ip(): behind a reverse proxy, list the proxy in TRUSTED_PROXIES or every visitor
 * looks like the proxy.
 */
class RestrictToAllowedIps
{
    /** Admin authentication screens served outside /admin (routes/web.php, Fortify). */
    private const AUTH_PATHS = ['login', 'logout', 'forgot-password', 'reset-password', 'reset-password/*', 'accept-invitation/*', 'two-factor-challenge', 'two-factor-setup', 'user/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('security.admin_ip_allowlist');

        if ($allowed !== [] && $this->isProtected($request) && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(403);
        }

        return $next($request);
    }

    private function isProtected(Request $request): bool
    {
        $horizon = trim((string) config('horizon.path', 'horizon'), '/');

        return $request->is('admin', 'admin/*', $horizon, $horizon.'/*', ...self::AUTH_PATHS);
    }
}
