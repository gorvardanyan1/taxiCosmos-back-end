<?php

namespace App\Services\Push;

use App\Enums\AppClient;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Who a push token belongs to. A token is one physical phone, so it belongs to exactly one user at a time: registering it for
 * someone else moves it (shared phones). A registration is tied to the sign-in's install id, so a logout, or another person
 * signing in on the same install, ends it: the previous person never receives the next person's pushes.
 */
final class DeviceRegistry
{
    /**
     * Register (or refresh) the push token of the signed-in person's phone. Safe to repeat on every login and token refresh.
     */
    public function register(User $user, AppClient $app, ?string $deviceId, string $pushToken, DevicePlatform $platform, ?string $locale, ?string $appVersion): Device
    {
        try {
            return $this->store($user, $app, $deviceId, $pushToken, $platform, $locale, $appVersion);
        } catch (UniqueConstraintViolationException) {
            // Two requests registered the same new token at once; the other one won, so this is now an update.
            return $this->store($user, $app, $deviceId, $pushToken, $platform, $locale, $appVersion);
        }
    }

    private function store(User $user, AppClient $app, ?string $deviceId, string $pushToken, DevicePlatform $platform, ?string $locale, ?string $appVersion): Device
    {
        return DB::transaction(function () use ($user, $app, $deviceId, $pushToken, $platform, $locale, $appVersion) {
            $device = Device::query()->where('push_token', $pushToken)->lockForUpdate()->first() ?? new Device;

            if ($deviceId !== null) {
                // This install has one registration: an older token of the same person (Expo rotated it) and anyone else's
                // registration on the same install end here.
                Device::query()->active()->where('app', $app->value)->where('device_id', $deviceId)->where('push_token', '!=', $pushToken)
                    ->update(['revoked_at' => now()]);
            }

            $device->forceFill([
                'push_token' => $pushToken,
                'user_id' => $user->getKey(),
                'app' => $app,
                'platform' => $platform,
                'device_id' => $deviceId,
                'locale' => $locale,
                'app_version' => $appVersion,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ])->save();

            return $device;
        });
    }

    /**
     * Deregister one of the person's own devices. Someone else's id is "not found". Repeating it changes nothing.
     */
    public function deregister(User $user, int $deviceId): void
    {
        $device = Device::query()->where('user_id', $user->getKey())->whereKey($deviceId)->firstOrFail();

        $this->revoke($device);
    }

    public function revoke(Device $device): void
    {
        if ($device->isActive()) {
            $device->forceFill(['revoked_at' => now()])->save();
        }
    }

    /** The sign-in on an install ended (logout, replaced session, stolen refresh token). */
    public function revokeInstall(int $userId, AppClient $app, string $deviceId): void
    {
        Device::query()->active()->where('user_id', $userId)->where('app', $app->value)->where('device_id', $deviceId)->update(['revoked_at' => now()]);
    }

    /** A different person signed in on this install: whoever was registered on it before is no longer there. */
    public function revokeOthersOnInstall(User $user, AppClient $app, string $deviceId): void
    {
        Device::query()->active()->where('app', $app->value)->where('device_id', $deviceId)->where('user_id', '!=', $user->getKey())->update(['revoked_at' => now()]);
    }

    /** "Log out everywhere", account deletion. */
    public function revokeAllOf(int $userId): void
    {
        Device::query()->active()->where('user_id', $userId)->update(['revoked_at' => now()]);
    }
}
