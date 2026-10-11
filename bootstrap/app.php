<?php

use App\Exceptions\ConflictException;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureMobileClient;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectNullBytes;
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
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

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
                return Inertia::render('Errors/Show', ['status' => $status, 'locale' => App::getLocale()])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
