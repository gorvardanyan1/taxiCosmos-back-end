<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Tests\E2e\E2eGuard;
use Tests\E2e\E2eMakeCommand;
use Tests\E2e\E2eResetCommand;
use Tests\E2e\E2eSqlCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BlindIndex::class, fn () => BlindIndex::fromConfig());

        $this->app->bind(SmsSender::class, fn () => match (config('sms.driver')) {
            'log' => new LogSmsSender,
            default => throw new RuntimeException('Unknown SMS_DRIVER "'.config('sms.driver').'".'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // super_admin passes every Gate/permission check; returning null defers to normal checks.
        // Admin passwords: 12+ characters with mixed case, a number and a symbol, and not found in
        // known breaches (haveibeenpwned k-anonymity API).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());

        $this->mobileAuthRateLimits();
        $this->registerE2eTooling();

        Gate::before(function ($user) {
            return $user instanceof User && $user->isSuperAdmin() ? true : null;
        });
    }

    /**
     * Mobile sign-in throttles. Asking for codes and guessing codes are limited separately, so one
     * cannot be used up by (or hide) the other. The key uses the normalised phone, so "091…" and
     * "+374 91…" share a budget. Documented in docs/rate-limits.md.
     */
    private function mobileAuthRateLimits(): void
    {
        $phone = function (Request $request): string {
            $raw = (string) $request->input('phone');

            return E164PhoneNumber::tryNormalize($raw) ?? 'raw:'.sha1($raw);
        };

        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinute(1)->by('otp-request:'.$phone($request).'|'.$request->ip()),
            Limit::perHour(5)->by('otp-request-phone:'.$phone($request)),
            Limit::perHour(30)->by('otp-request-ip:'.$request->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('otp-verify:'.$phone($request).'|'.$request->ip()),
            Limit::perHour(30)->by('otp-verify-phone:'.$phone($request)),
            Limit::perMinute(30)->by('otp-verify-ip:'.$request->ip()),
        ]);

        RateLimiter::for('social-login', fn (Request $request) => Limit::perMinute(20)->by('social-login:'.$request->ip()));

        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute(30)->by('auth-refresh:'.$request->ip()));
    }

    /**
     * The Cypress helpers (tests/E2e) are dev-only code: registered only in the e2e and testing
     * environments, and each command checks the environment and database again before acting.
     */
    private function registerE2eTooling(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->environment(['e2e', 'testing']) || ! class_exists(E2eGuard::class)) {
            return;
        }

        $this->commands([
            E2eResetCommand::class,
            E2eMakeCommand::class,
            E2eSqlCommand::class,
        ]);
    }
}
