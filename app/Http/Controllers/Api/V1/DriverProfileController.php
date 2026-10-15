<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverProfileRequest;
use App\Http\Resources\Api\V1\DriverApplicationResource;
use App\Models\User;
use App\Services\Drivers\DriverOnboardingService;
use Illuminate\Http\JsonResponse;

/** PUT /api/v1/driver/profile (P4-T4): the licence number and expiry and the home zone. A change to an approved licence sends it back to review. */
class DriverProfileController extends Controller
{
    public function __invoke(DriverProfileRequest $request, DriverOnboardingService $onboarding): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $onboarding->updateProfile($user, $onboarding->profileOf($user), $request->profile());

        return response()->json(['data' => (new DriverApplicationResource($profile))->resolve()]);
    }
}
