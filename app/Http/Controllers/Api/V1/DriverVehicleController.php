<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverVehicleRequest;
use App\Http\Resources\Api\V1\DriverVehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Drivers\DriverOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver's own vehicles (P4-T4). Every vehicle is looked up through the signed-in driver, so another driver's vehicle id is "not
 * found". A new vehicle waits for review; changing an approved one sends it back.
 */
class DriverVehicleController extends Controller
{
    public function __construct(private readonly DriverOnboardingService $onboarding) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->onboarding->profileOf($this->user($request));

        return response()->json(['data' => DriverVehicleResource::collection(Vehicle::query()->where('driver_id', $profile->id)->orderBy('id')->get())->resolve()]);
    }

    public function store(DriverVehicleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        ['vehicle' => $vehicle, 'created' => $created] = $this->onboarding->addVehicle($user, $this->onboarding->profileOf($user), $request->vehicle());

        return response()->json(['data' => (new DriverVehicleResource($vehicle))->resolve()], $created ? 201 : 200);
    }

    public function update(DriverVehicleRequest $request, int $vehicle): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->onboarding->profileOf($user);
        $own = Vehicle::query()->where('driver_id', $profile->id)->findOrFail($vehicle);
        $this->onboarding->updateVehicle($user, $profile, $own, $request->vehicle());

        return response()->json(['data' => (new DriverVehicleResource($own->refresh()))->resolve()]);
    }

    public function destroy(Request $request, int $vehicle): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->onboarding->profileOf($user);
        $this->onboarding->removeVehicle($user, $profile, Vehicle::query()->where('driver_id', $profile->id)->findOrFail($vehicle));

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
