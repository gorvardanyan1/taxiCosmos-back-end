<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A NUL byte (\0) in any input value or key is refused with 400. PostgreSQL text cannot hold it and
 * the driver silently cuts the value at that byte, so a name or email would be stored shortened
 * (and validation would have approved the longer text). Runs first on the web and API groups.
 */
class RejectNullBytes
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->containsNull($request->all()) || $this->containsNull($request->query())) {
            return response()->json(['message' => __('The request contains invalid characters.')], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function containsNull(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && str_contains($key, "\0")) {
                return true;
            }

            if (is_string($value) && str_contains($value, "\0")) {
                return true;
            }

            if (is_array($value) && $this->containsNull($value)) {
                return true;
            }
        }

        return false;
    }
}
