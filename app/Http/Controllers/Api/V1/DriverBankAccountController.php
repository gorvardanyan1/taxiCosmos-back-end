<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverBankAccountRequest;
use App\Http\Resources\Api\V1\DriverBankAccountResource;
use App\Models\DriverBankAccount;
use App\Models\User;
use App\Services\Drivers\DriverOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver's own payout accounts (P4-T4). The number is stored encrypted and only its last four characters ever come back. An admin
 * verifies an account before money is paid to it (P6-T8). Another driver's account id is "not found".
 */
class DriverBankAccountController extends Controller
{
    public function __construct(private readonly DriverOnboardingService $onboarding) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->onboarding->profileOf($this->user($request));

        return response()->json(['data' => DriverBankAccountResource::collection(DriverBankAccount::query()->where('driver_id', $profile->id)->orderBy('id')->get())->resolve()]);
    }

    public function store(DriverBankAccountRequest $request): JsonResponse
    {
        $user = $this->user($request);
        ['account' => $account, 'created' => $created] = $this->onboarding->addBankAccount($user, $this->onboarding->profileOf($user), $request->account());

        return response()->json(['data' => (new DriverBankAccountResource($account))->resolve()], $created ? 201 : 200);
    }

    public function destroy(Request $request, int $account): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->onboarding->profileOf($user);
        $this->onboarding->removeBankAccount($user, $profile, DriverBankAccount::query()->where('driver_id', $profile->id)->findOrFail($account));

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
