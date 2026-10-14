<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the service-to-service endpoints the Node real-time service calls (P8-T3): the shared SOCKET_SERVICE_TOKEN
 * (X-Service-Token, or Authorization: Bearer) and a caller address on SOCKET_SERVICE_ALLOWED_IPS. It fails closed: a token that is
 * not configured (or shorter than 32 characters) or an empty address list refuses every call. A wrong or missing token is 401 and
 * says nothing about why; a wrong address is 403.
 */
class AuthenticateSocketService
{
    private const MIN_TOKEN_LENGTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('security.socket.service_token');
        $given = (string) ($request->header('X-Service-Token') ?: $request->bearerToken());

        // Compared as hashes so the length of the secret is not revealed by timing.
        $tokenOk = strlen($expected) >= self::MIN_TOKEN_LENGTH && $given !== '' && hash_equals(hash('sha256', $expected), hash('sha256', $given));

        if (! $tokenOk) {
            return response()->json(['message' => __('Unauthenticated.')], 401);
        }

        $allowed = config('security.socket.allowed_ips');

        if ($allowed === [] || ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return response()->json(['message' => __('Forbidden.')], 403);
        }

        return $next($request);
    }
}
