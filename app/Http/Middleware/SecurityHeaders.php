<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side hardening headers for the admin web surface: every response outside /api
 * (pages, redirects, error pages, unmatched URLs). Registered as global middleware so it also wraps
 * responses that never reached the web group, such as a 404 or a 403 from the IP allowlist.
 *
 * Horizon ships its own inline scripts, so /horizon gets every header except the CSP. A header a route sets itself
 * is left as it is.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Hot mode (`npm run dev`) adds an inline script (the React-refresh preamble). It gets a per-request nonce,
        // which Vite puts on its own tags while it renders the page and the policy below allows; never 'unsafe-inline'.
        $nonce = $this->devServer() !== null ? app(Vite::class)->useCspNonce() : null;

        $response = $next($request);

        if ($request->is('api/*')) {
            return $response;
        }

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
        ];

        // Browsers ignore HSTS on plain HTTP, so it is only sent where it means something.
        $maxAge = (int) config('security.headers.hsts_max_age');
        if ($request->isSecure() && $maxAge > 0) {
            $headers['Strict-Transport-Security'] = "max-age={$maxAge}; includeSubDomains";
        }

        // A route that sends its own policy (the signed document files: `default-src 'none'; sandbox`) keeps it.
        if (! $this->isHorizon($request) && ! $response->headers->has('Content-Security-Policy') && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $name = config('security.headers.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers[$name] = $this->policy($nonce);
        }

        // These are defaults: a header the route already set on purpose is never overwritten.
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function isHorizon(Request $request): bool
    {
        $path = trim((string) config('horizon.path', 'horizon'), '/');

        return $request->is($path, $path.'/*');
    }

    private function policy(?string $nonce): string
    {
        $styles = ["'self'", "'unsafe-inline'", ...config('security.headers.style_sources')];
        $scripts = ["'self'"];
        $connect = ["'self'"];

        // `npm run dev`: the page loads modules from, and talks to, the Vite dev server.
        if ($nonce !== null && $dev = $this->devServer()) {
            $scripts = [...$scripts, $dev, "'nonce-{$nonce}'"];
            $styles[] = $dev;
            $connect = [...$connect, $dev, str_replace('http', 'ws', $dev)];
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scripts,
            'style-src' => $styles,
            'font-src' => ["'self'", 'data:', ...config('security.headers.font_sources')],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'connect-src' => $connect,
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return implode('; ', array_map(fn (string $name, array $sources) => $name.' '.implode(' ', $sources), array_keys($directives), $directives));
    }

    private function devServer(): ?string
    {
        $hot = app(Vite::class)->hotFile();

        return is_file($hot) ? rtrim(trim((string) file_get_contents($hot)), '/') : null;
    }
}
