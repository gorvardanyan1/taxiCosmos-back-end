<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin sessions never use "remember me": a long-lived remember cookie would silently sign an
 * admin back in after the idle timeout (EnforceAdminIdleTimeout) has expired the session.
 * Whatever the client sends, Fortify sees remember = false on sign-in.
 */
class DisableRememberMe
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('login')) {
            $request->merge(['remember' => false]);
        }

        return $next($request);
    }
}
