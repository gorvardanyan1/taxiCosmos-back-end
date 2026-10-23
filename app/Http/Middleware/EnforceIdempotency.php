<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a mutating mobile call safe to retry: the app sends an `Idempotency-Key` header (8 to 64 of A-Z a-z 0-9 _ -) with every one.
 *
 *  - The first call with a key runs and, when it succeeds (2xx), its answer is stored under (signed-in user, key).
 *  - A repeat with the same key and the same request gets that stored answer back (`Idempotent-Replayed: true`) and does nothing.
 *  - The same key with a different method, path or body is refused (422 idempotency_key_reused): a mistake, not a retry.
 *  - A repeat while the first call is still running is refused (409 idempotency_in_progress, `Retry-After: 1`).
 *  - A refusal or failure (any answer that is not 2xx, a 500 included) is not stored, so the retry runs again and meets the real state
 *    (for example 409 with the current trip).
 *
 * The row is claimed before the call runs, so two simultaneous calls with one key cannot both run. A claim whose call died
 * (the process was killed) is taken over once it is two minutes old. The header is required here: these routes have no meaning without it.
 */
class EnforceIdempotency
{
    private const KEY_PATTERN = '/^[A-Za-z0-9_\-]{8,64}$/';

    private const STALE_AFTER_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return $this->refuse(422, 'idempotency_key_required', __('This request needs an Idempotency-Key header of 8 to 64 letters, digits, - or _.'));
        }

        $userId = (int) $request->user()->getAuthIdentifier();
        $fingerprint = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());

        $claim = $this->claim($userId, $key, $fingerprint);

        if ($claim instanceof Response) {
            return $claim;
        }

        // An exception thrown by the call reaches here as the 500 response the router makes of it, so a failure is handled like any refusal.
        $response = $next($request);

        if ($response->isSuccessful() && $response instanceof JsonResponse) {
            $claim->forceFill(['response_status' => $response->getStatusCode(), 'response_body' => $response->getData(true)])->save();
        } else {
            $claim->delete();
        }

        return $response;
    }

    /**
     * Takes the key, or says what to answer instead. The row is inserted with ON CONFLICT DO NOTHING: of two simultaneous calls exactly one
     * inserts it, and the other reads it (a unique-violation exception would also poison an open transaction).
     */
    private function claim(int $userId, string $key, string $fingerprint): ApiIdempotencyKey|Response
    {
        $now = now();
        $inserted = ApiIdempotencyKey::query()->insertOrIgnore(['user_id' => $userId, 'key' => $key, 'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now]);
        $existing = ApiIdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();

        if ($existing === null) {
            // Deleted between the insert and the read (a failed first call cleaned up): try once more.
            return $this->claim($userId, $key, $fingerprint);
        }

        if ($inserted === 1) {
            return $existing;
        }

        if (! hash_equals($existing->fingerprint, $fingerprint)) {
            return $this->refuse(422, 'idempotency_key_reused', __('This Idempotency-Key was already used for a different request.'));
        }

        if ($existing->isFinished()) {
            return response()->json($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
        }

        if ($existing->created_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
            $existing->delete();

            return $this->claim($userId, $key, $fingerprint);
        }

        return $this->refuse(409, 'idempotency_in_progress', __('The first request with this Idempotency-Key is still running. Try again in a moment.'), ['Retry-After' => '1']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function refuse(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status, $headers);
    }
}
