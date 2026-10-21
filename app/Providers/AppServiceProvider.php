<?php

namespace App\Providers;

use App\Contracts\GeoProvider;
use App\Contracts\PushSender;
use App\Contracts\SmsSender;
use App\Events\DriverDocumentReviewed;
use App\Events\Realtime\SupportTicketUpdated;
use App\Events\Realtime\TripOffer;
use App\Events\Realtime\TripStatusChanged;
use App\Events\Realtime\UserSuspended;
use App\Listeners\NotifyByPush;
use App\Models\User;
use App\Services\Fares\RouteEstimator;
use App\Services\Geo\CachingGeoProvider;
use App\Services\Geo\FakeGeoProvider;
use App\Services\Geo\GoogleMapsProvider;
use App\Services\Geo\NominatimThrottle;
use App\Services\Geo\OsmProvider;
use App\Services\Geo\TwoGisProvider;
use App\Services\Payments\PaymentGateways;
use App\Services\Push\ExpoPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Sms\LogSmsSender;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // The maps provider: MAPS_PROVIDER picks the implementation, the cache sits in front of it. Callers ask for GeoProvider.
        $this->app->bind(GeoProvider::class, function ($app) {
            $name = (string) config('services.maps.provider');

            $inner = match ($name) {
                'google' => new GoogleMapsProvider((string) config('services.maps.google.key')),
                '2gis' => new TwoGisProvider((string) config('services.maps.2gis.key')),
                'osm' => new OsmProvider(
                    [
                        'nominatim_url' => (string) config('services.maps.osm.nominatim_url'),
                        'osrm_url' => (string) config('services.maps.osm.osrm_url'),
                        'user_agent' => (string) config('services.maps.osm.user_agent'),
                        'country_codes' => (string) config('services.maps.osm.country_codes'),
                        'search_cache_ttl' => (int) config('services.maps.osm.search_cache_ttl'),
                    ],
                    new NominatimThrottle((int) config('services.maps.osm.nominatim_interval_ms'), (int) config('services.maps.osm.nominatim_max_wait_seconds')),
                    $app->make(RouteEstimator::class),
                ),
                'fake' => $app->environment(['local', 'testing', 'e2e'])
                    ? $app->make(FakeGeoProvider::class)
                    : throw new RuntimeException('MAPS_PROVIDER=fake is only for local, testing and e2e environments.'),
                default => throw new RuntimeException("Unknown MAPS_PROVIDER \"{$name}\" (use osm, google or 2gis)."),
            };

            return new CachingGeoProvider($inner, $name, $app->make(CacheRepository::class));
        });

        // Push delivery (P8-T4): PUSH_DRIVER picks expo (real) or log (nothing is sent). The default outside production is log, so
        // local and staging never push unless it is switched on explicitly; production has no default and must choose.
        $this->app->bind(PushSender::class, fn () => match (config('services.push.driver')) {
            'expo' => new ExpoPushSender(config('services.push.expo.access_token')),
            'log' => new LogPushSender,
            default => throw new RuntimeException('PUSH_DRIVER must be set to expo or log (got "'.config('services.push.driver').'").'),
        });

        // Card gateways (P6-T2): PAYMENT_GATEWAY names the one new cards are saved with, PAYMENT_DRIVER=fake swaps in the no-network
        // gateway (local, testing, e2e only). Only App\Services\Payments asks for these.
        $this->app->singleton(PaymentGateways::class, fn ($app) => new PaymentGateways($app->make(CacheRepository::class), (string) $app->environment()));

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
        // Push notifications (P8-T4) follow the domain events; the events fire after commit.
        Event::listen(TripOffer::class, [NotifyByPush::class, 'tripOffer']);
        Event::listen(TripStatusChanged::class, [NotifyByPush::class, 'tripStatusChanged']);
        Event::listen(UserSuspended::class, [NotifyByPush::class, 'userSuspended']);
        Event::listen(SupportTicketUpdated::class, [NotifyByPush::class, 'supportTicketUpdated']);
        Event::listen(DriverDocumentReviewed::class, [NotifyByPush::class, 'documentReviewed']);

        // super_admin passes every Gate/permission check; returning null defers to normal checks.
        // Admin passwords: 12+ characters with mixed case, a number and a symbol, and not found in
        // known breaches (haveibeenpwned k-anonymity API).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());

        $this->mobileAuthRateLimits();
        $this->generalRateLimits();
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

        // Revealing a bank account number is logged every time; the limit stops a stolen session from harvesting numbers.
        RateLimiter::for('bank-account-reveal', fn (Request $request) => Limit::perMinute(10)->by('bank-account-reveal:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A device registration is a login-time write of a token chosen by the client.
        RateLimiter::for('devices', fn (Request $request) => Limit::perMinute(config('security.rate_limits.devices'))->by('devices:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Opening a ticket and writing on one: per person, generous for a conversation but not for flooding the staff.
        RateLimiter::for('support-tickets', fn (Request $request) => Limit::perMinute(config('security.rate_limits.support_tickets'))->by('support-tickets:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Onboarding writes are a handful per driver; an upload is the heavy one, so it has its own, tighter budget.
        RateLimiter::for('driver-onboarding', fn (Request $request) => Limit::perMinute(config('security.rate_limits.driver_onboarding'))->by('driver-onboarding:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('driver-documents', fn (Request $request) => Limit::perMinute(config('security.rate_limits.driver_documents'))->by('driver-documents:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Pings come every few seconds from a working driver, so the budget is per driver and generous; the toggle is not.
        RateLimiter::for('driver-location', fn (Request $request) => Limit::perMinute(config('security.rate_limits.driver_location'))->by('driver-location:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('driver-availability', fn (Request $request) => Limit::perMinute(config('security.rate_limits.driver_availability'))->by('driver-availability:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Each estimate stores a quote, so it is limited per rider (not per address, which phones share).
        // The Node service asks once per socket connect, so the ceiling is high; it is per calling address.
        RateLimiter::for('socket-auth', fn (Request $request) => Limit::perMinute(config('security.rate_limits.socket_auth'))->by('socket-auth:'.$request->ip()));

        RateLimiter::for('socket-ticket', fn (Request $request) => Limit::perMinute(config('security.rate_limits.socket_ticket'))->by('socket-ticket:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('fare-estimate', fn (Request $request) => Limit::perMinute(30)->by('fare-estimate:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * The ceilings behind the specific limiters: one per surface, so a client that stays under every endpoint's own
     * limit still cannot hammer the app. Keyed by the signed-in user where there is one (phones share carrier
     * addresses), otherwise by IP. Ceilings come from config/security.php; documented in docs/rate-limits.md.
     */
    private function generalRateLimits(): void
    {
        $caller = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        // Every /admin page and action, per signed-in admin.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(config('security.rate_limits.admin'))->by('admin:'.$caller($request)));

        // The admin sign-in endpoints Fortify registers (password reset, two-factor, ...), per address and endpoint.
        // POST /login has its own, stricter limiter (FortifyServiceProvider), so it is left out here.
        RateLimiter::for('admin-auth', fn (Request $request) => $request->is('login')
            ? Limit::none()
            : Limit::perMinute(config('security.rate_limits.admin_auth'))->by('admin-auth:'.$request->ip().'|'.$request->path()));

        // Actions that ask for the account password again (two-factor enable, disable, recovery codes, reset): each attempt
        // is a password guess, so a stolen session cannot be used to find the password. Per signed-in admin.
        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(5)->by('password-confirm:'.$caller($request)));

        // Confirming the setup with a code from the authenticator app: six-digit guesses, per signed-in admin.
        RateLimiter::for('two-factor-confirm', fn (Request $request) => Limit::perMinute(5)->by('two-factor-confirm:'.$caller($request)));

        // Every authenticated /api/v1 call, per rider or driver.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('security.rate_limits.api'))->by('api:'.$caller($request)));

        // The maps endpoints (/api/v1/geo/*): every call that misses the cache costs money, so each rider or driver gets a budget.
        RateLimiter::for('geo', fn (Request $request) => Limit::perMinute(config('security.rate_limits.geo'))->by('geo:'.$caller($request)));

        // GET /api/v1/app-config is public (every app start, before sign-in), so it is limited by address.
        RateLimiter::for('app-config', fn (Request $request) => Limit::perMinute(config('security.rate_limits.app_config'))->by('app-config:'.$request->ip()));

        // Payment endpoints (P6) attach this: card testing and double charges are attacks, not traffic.
        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute(config('security.rate_limits.payment'))->by('payment:'.$caller($request)));
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
