<?php

namespace App\Services\Push;

use App\Enums\PushType;
use App\Jobs\SendPushNotification;
use App\Models\Device;
use App\Models\User;
use InvalidArgumentException;

/**
 * The one way to push something to a person: `notify($user, PushType::..., ['trip_id' => 12])`. It picks the person's active
 * devices of the right apps and queues the delivery (nothing is sent inside the request or the database transaction).
 */
final class PushNotifier
{
    /**
     * @param  array<string, int|string>  $data  ids for the app to deep-link with, never names, addresses or money
     * @return int how many devices the push was queued for
     */
    public function notify(User|int $user, PushType $type, array $data = []): int
    {
        foreach ($data as $key => $value) {
            if (! is_int($value) && ! is_string($value)) {
                throw new InvalidArgumentException("Push data [{$key}] must be an int or a string: it carries ids only.");
            }
        }

        $user = $user instanceof User ? $user : User::query()->find($user);

        // Only an active account is pushed to; the suspension notice itself is the one exception.
        if ($user === null || (! $user->isActive() && ! $type->isFinalNotice())) {
            return 0;
        }

        $deviceIds = Device::query()->active()->where('user_id', $user->getKey())->whereIn('app', array_map(fn ($app) => $app->value, $type->apps()))->pluck('id')->all();

        if ($deviceIds === []) {
            return 0;
        }

        SendPushNotification::dispatch($user->getKey(), $type, $deviceIds, $data);

        return count($deviceIds);
    }
}
