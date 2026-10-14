<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverLocationRequest;
use App\Services\Drivers\DriverLocationService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/driver/location (P8-T2): the REST fallback for the driver's position (the Node service normally writes it).
 * Contract and Redis/MongoDB layout: docs/driver-location.md.
 */
class DriverLocationController extends Controller
{
    public function __invoke(DriverLocationRequest $request, DriverLocationService $locations): JsonResponse
    {
        $recordedAt = $locations->record($request->user(), $request->point(), $request->heading(), $request->speed(), $request->accuracy());

        return response()->json(['recorded_at' => $recordedAt->utc()->format('Y-m-d\TH:i:s.v\Z')]);
    }
}
