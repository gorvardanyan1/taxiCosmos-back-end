<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DriverApplicationResource;
use App\Models\User;
use App\Services\Drivers\DriverOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/driver/application (P4-T4): the signed-in person applies to drive. Creates the pending driver profile (an admin sees it
 * in the Drivers list) and sets the driver flag. Applying again returns the application as it is.
 */
class DriverApplicationController extends Controller
{
    public function __invoke(Request $request, DriverOnboardingService $onboarding): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        ['profile' => $profile, 'created' => $created] = $onboarding->apply($user);

        return response()->json(['data' => (new DriverApplicationResource($profile))->resolve()], $created ? 201 : 200);
    }
}
