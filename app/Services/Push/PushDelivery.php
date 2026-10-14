<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use App\Data\PushMessage;
use App\Enums\PushType;
use App\Exceptions\PushDeliveryException;
use App\Models\Device;
use App\Models\PushTicket;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\Log;

/**
 * Sends one notification to a person's devices (run by the queued SendPushNotification job). It decides again, at the moment of
 * sending, who is still entitled to it: a device that logged out, moved to another person or was revoked since the push was
 * queued is skipped, so a push never reaches the next person on the same phone.
 */
final class PushDelivery
{
    public function __construct(
        private readonly PushSender $sender,
        private readonly DeviceRegistry $devices,
    ) {}

    /**
     * @param  list<int>  $deviceIds
     * @param  array<string, int|string>  $data
     *
     * @throws PushDeliveryException when the push service cannot be used now (the job retries)
     */
    public function deliver(int $userId, PushType $type, array $deviceIds, array $data): void
    {
        $user = User::query()->find($userId);

        if ($user === null || (! $user->isActive() && ! $type->isFinalNotice())) {
            return;
        }

        // Still this person's, still registered, still the right app.
        $devices = Device::query()->active()->where('user_id', $userId)->whereIn('id', $deviceIds)
            ->whereIn('app', array_map(fn ($app) => $app->value, $type->apps()))->get();

        if ($devices->isEmpty()) {
            return;
        }

        $messages = $devices->map(function (Device $device) use ($user, $type, $data) {
            $locale = $this->locale($user, $device);

            return new PushMessage($device->push_token, $type->title($locale), $type->body($locale), ['type' => $type->value, ...$data],
                $type->isHighPriority(), $type->androidChannel(), $type->isTimeSensitive(), $type->ttlSeconds());
        })->values()->all();

        $results = $this->sender->send($messages);

        foreach ($devices->values() as $i => $device) {
            $result = $results[$i] ?? null;

            if ($result === null) {
                continue;
            }

            if ($result->invalidToken) {
                // The push service says the phone no longer has this token: stop using it.
                $this->devices->revoke($device);
                Log::info('A push token was revoked because the push service reported it invalid.', ['device_id' => $device->getKey(), 'error' => $result->error]);
            } elseif ($result->ok && $result->ticketId !== null) {
                PushTicket::query()->create(['device_id' => $device->getKey(), 'ticket_id' => $result->ticketId, 'created_at' => now()]);
            } elseif (! $result->ok) {
                Log::warning('A push notification was refused by the push service.', ['device_id' => $device->getKey(), 'type' => $type->value, 'error' => $result->error]);
            }
        }
    }

    /** The user's own language; the phone's when the account has none; English otherwise. */
    private function locale(User $user, Device $device): string
    {
        foreach ([$user->locale, $device->locale] as $locale) {
            if (Locales::isSupported($locale)) {
                return $locale;
            }
        }

        return Locales::default();
    }
}
