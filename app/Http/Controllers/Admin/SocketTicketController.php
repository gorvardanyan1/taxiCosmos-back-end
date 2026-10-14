<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Socket\SocketAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A short-lived, single-use ticket an admin's browser hands to the socket service instead of the session cookie (P8-T3). Any
 * signed-in admin may ask; the socket service exchanges it at POST /api/v1/internal/socket-auth.
 */
class SocketTicketController extends Controller
{
    public function __invoke(Request $request, SocketAuthService $sockets): JsonResponse
    {
        return response()->json($sockets->issueAdminTicket($request->user()))
            ->header('Cache-Control', 'no-store, private');
    }
}
