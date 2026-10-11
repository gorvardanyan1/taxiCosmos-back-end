<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language of the response.
 *
 * Admin panel (web): the signed-in admin's saved language (My Account) → the guest's choice on the
 * sign-in screens (?lang=ru, kept in the session) → the browser's Accept-Language → English.
 *
 * Mobile API: Accept-Language (the app knows the language it shows) → the user's saved language →
 * English.
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->is('api/*') ? $this->forApi($request) : $this->forWeb($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }

    private function forWeb(Request $request): string
    {
        $user = $request->user();
        $saved = Locales::forUser($user instanceof User ? $user : null);

        if ($saved !== null) {
            return $saved;
        }

        if ($request->hasSession()) {
            if (Locales::isSupported($request->query('lang'))) {
                $request->session()->put(self::SESSION_KEY, $request->query('lang'));
            }

            if (Locales::isSupported($request->session()->get(self::SESSION_KEY))) {
                return $request->session()->get(self::SESSION_KEY);
            }
        }

        return Locales::fromHeader($request) ?? Locales::default();
    }

    private function forApi(Request $request): string
    {
        $user = $request->user('sanctum');

        return Locales::fromHeader($request) ?? Locales::forUser($user instanceof User ? $user : null) ?? Locales::default();
    }
}
