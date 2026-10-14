<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SocketAuthRequest;
use App\Services\Socket\SocketAuthService;
use Illuminate\Http\JsonResponse;

/**
 * The Node real-time service asks who owns a mobile access token (or an admin socket ticket). Service-to-service only; the contract
 * is in docs/realtime-events.md.
 *
 * @hideFromAPIDocumentation
 */
class SocketAuthController extends Controller
{
    public function __invoke(SocketAuthRequest $request, SocketAuthService $sockets): JsonResponse
    {
        $identity = $request->filled('token')
            ? $sockets->identifyToken($request->string('token')->toString())
            : $sockets->identifyTicket($request->string('ticket')->toString());

        // One answer for every reason (unknown, expired, revoked, suspended, wrong app), so the endpoint cannot be used to probe accounts.
        return $identity === null
            ? response()->json(['message' => __('Unauthenticated.')], 401)
            : response()->json($identity);
    }
}
