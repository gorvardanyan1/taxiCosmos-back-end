<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PhoneChangeRequest;
use App\Http\Requests\Api\V1\PhoneVerifyRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\MeResource;
use App\Models\User;
use App\Services\Account\AccountProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own account for the rider and driver apps (P3-T5): GET and PATCH /api/v1/me, and the phone change
 * (a code to the new number, then the code back).
 */
class MeController extends Controller
{
    public function __construct(private readonly AccountProfileService $profile) {}

    public function show(Request $request): JsonResponse
    {
        return $this->present($request->user());
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        return $this->present($this->profile->update($request->user(), $request->changes()));
    }

    public function requestPhone(PhoneChangeRequest $request): JsonResponse
    {
        $this->profile->requestPhoneChange($request->user(), $request->phone());

        return response()->json([
            'message' => __('If the number can receive a code, it is on its way.'),
            'expires_in' => config('taxikosmos.mobile_auth.otp.ttl_minutes') * 60,
        ], 202);
    }

    public function verifyPhone(PhoneVerifyRequest $request): JsonResponse
    {
        return $this->present($this->profile->confirmPhoneChange($request->user(), $request->phone(), $request->validated('code')));
    }

    private function present(User $user): JsonResponse
    {
        return response()->json(['user' => (new MeResource($user->fresh(['driverProfile.primaryVehicle'])))->resolve()]);
    }
}
