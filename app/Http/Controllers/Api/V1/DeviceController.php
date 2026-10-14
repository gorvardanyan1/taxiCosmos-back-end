<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppClient;
use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterDeviceRequest;
use App\Models\Device;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\Push\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Push token registration for the rider and driver apps (P8-T4): POST /api/v1/devices and DELETE /api/v1/devices/{id}.
 * Layout, ownership and the shared-phone rules: docs/push-notifications.md.
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly MobileSessionService $sessions,
    ) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $device = $this->devices->register(
            $request->user(),
            $token->can(AppClient::Driver->value) ? AppClient::Driver : AppClient::Rider,
            // The install id from this sign-in, so a logout (or another person signing in on this install) ends the registration.
            $this->sessions->currentSession($token)?->device_id,
            $request->validated('push_token'),
            DevicePlatform::from($request->validated('platform')),
            $request->validated('locale'),
            $request->validated('app_version'),
        );

        return response()->json(['device' => $this->present($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        $this->devices->deregister($request->user(), $device);

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Device $device): array
    {
        // The push token is not echoed back: the app already has it.
        return [
            'id' => $device->id,
            'app' => $device->app->value,
            'platform' => $device->platform->value,
            'locale' => $device->locale,
            'app_version' => $device->app_version,
            'last_seen_at' => $device->last_seen_at->toIso8601String(),
        ];
    }
}
