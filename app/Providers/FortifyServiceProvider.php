<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\AdminUserProvider;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Listeners\RecordAdminLogin;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Admin authentication (P3-T1): Fortify handles the POST endpoints; the Inertia screens are
 * our own GET routes. Only admin-tier users (flag + admin role + active) can sign in.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
    }

    public function boot(): void
    {
        Auth::provider('admin-eloquent', fn ($app, array $config) => new AdminUserProvider($app['hash'], $config['model']));

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Same failure ("These credentials do not match our records.") for a wrong password, an
        // unknown email and a rider/driver/deactivated account, so nothing is revealed.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->string('email')->lower()->value())->first();

            return $user !== null
                && $user->password !== null
                && Hash::check((string) $request->input('password'), $user->password)
                && $user->hasAdminAccess()
                ? $user
                : null;
        });

        // throttle:login on POST /login — 5 attempts per minute per email + IP, and a per-IP ceiling across all emails
        // (one address trying many accounts). The browser form (Inertia) gets the message on the sign-in page; any other
        // client gets a real 429. Both carry Retry-After.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip();
            $respond = function (Request $request, array $headers) {
                $seconds = (int) ($headers['Retry-After'] ?? 60);
                $message = trans('auth.throttle', ['seconds' => $seconds]);

                if (! $request->header('X-Inertia')) {
                    throw new ThrottleRequestsException($message, null, $headers);
                }

                return back()->withInput($request->only('email'))->withErrors(['email' => $message])->withHeaders($headers);
            };

            return [
                Limit::perMinute(5)->by($key)->response($respond),
                Limit::perMinute(config('security.rate_limits.login_ip'))->by('login-ip:'.$request->ip())->response($respond),
            ];
        });

        Event::listen(Login::class, RecordAdminLogin::class);
    }
}
