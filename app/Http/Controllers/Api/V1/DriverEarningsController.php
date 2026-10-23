<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DriverStatusException;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Services\Drivers\DriverEarningsSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/driver/earnings: the driver's own totals for today, the last 7 days and the last 30 days (the driver app's earnings screen;
 * the trip list with each trip's earnings is GET /api/v1/driver/trips). Amounts are integers in minor units of `currency`.
 */
class DriverEarningsController extends Controller
{
    public function __invoke(Request $request, DriverEarningsSummary $summary): JsonResponse
    {
        $profile = DriverProfile::query()->where('user_id', $request->user()->getKey())->with('homeZone')->first() ?? throw DriverStatusException::notVerified();

        return response()->json(['data' => $summary->for($profile)]);
    }
}
