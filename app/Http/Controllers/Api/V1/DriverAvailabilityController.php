<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DriverAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverAvailabilityRequest;
use App\Services\Drivers\DriverAvailabilityService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/driver/availability (P8-T2): the online / offline switch. Refusals carry a stable `code`
 * (see App\Exceptions\DriverStatusException); the change publishes driver.availability_changed.
 */
class DriverAvailabilityController extends Controller
{
    public function __invoke(DriverAvailabilityRequest $request, DriverAvailabilityService $availability): JsonResponse
    {
        $profile = $request->target() === DriverAvailability::Online
            ? $availability->goOnline($request->user())
            : $availability->goOffline($request->user());

        return response()->json(['availability' => $profile->availability->value]);
    }
}
