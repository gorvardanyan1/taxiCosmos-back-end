<?php

use App\Exceptions\ConflictException;
use App\Exceptions\DriverStatusException;
use App\Exceptions\GeoException;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureMobileClient;
use App\Http\Middleware\EnsureTwoFactorSetUp;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectNullBytes;
use App\Http\Middleware\RestrictToAllowedIps;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\Locales;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy the client address (rate limits, the admin IP allowlist) and the scheme (HSTS, Secure cookies)
        // are only right when the proxy is trusted: TRUSTED_PROXIES is a comma separated list of addresses/CIDRs, or "*".
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))));

        // The headers wrap everything (outermost), so they also land on the 403 of the IP allowlist and on unmatched URLs.
        // The allowlist runs after the framework's own global middleware, so it sees the client address TrustProxies resolved.
        $middleware->prepend(SecurityHeaders::class);
        $middleware->append(RestrictToAllowedIps::class);
        $middleware->web(prepend: [RejectNullBytes::class], append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->api(prepend: [SetLocale::class, RejectNullBytes::class]);
        $middleware->alias([
            'admin' => EnsureAdminAccess::class,
            'mobile' => EnsureMobileClient::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'admin.idle' => EnforceAdminIdleTimeout::class,
            'two-factor.required' => EnsureTwoFactorSetUp::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // "No such place / route" is an ordinary answer, not an error worth a stack trace in the log; an outage (502) is still reported.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof GeoException && $e->status === 404);
        // A driver told "you are offline / not approved" is a normal answer. The one failure (a position Redis would not take) is logged
        // where it happens, with the exception class only: a Redis error can quote the coordinates.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof DriverStatusException);

        // An action the record's state does not allow (a reviewed document, an already inactive zone) is a conflict (409). The Inertia admin gets
        // it as a flash message on the page it came from.
        $exceptions->render(function (ConflictException $e, Request $request) {
            return $request->header('X-Inertia')
                ? back()->with('error', $e->getMessage())
                : response()->json(['message' => $e->getMessage()], 409);
        });

        // Admin web errors render the template's error page through Inertia (outside debug mode).
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            // A URL that matched no route never went through the web middleware (so no session or
            // signed-in admin yet): the error page uses the browser's language.
            if (! $request->hasSession()) {
                App::setLocale(Locales::fromHeader($request) ?? Locales::default());
            }

            if ($status === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            if (! config('app.debug') && in_array($status, [403, 404, 429, 500, 503], true)) {
                // `locale` is for pages that never got the shared props (a URL that matched no route).
                $page = Inertia::render('Errors/Show', ['status' => $status, 'locale' => App::getLocale()])
                    ->toResponse($request)
                    ->setStatusCode($status);

                // A throttled client still needs to know when to come back.
                foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
                    if ($response->headers->has($header)) {
                        $page->headers->set($header, $response->headers->get($header));
                    }
                }

                return $page;
            }

            return $response;
        });
    })->create();
