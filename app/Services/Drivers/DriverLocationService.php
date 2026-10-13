<?php

namespace App\Services\Drivers;

use App\Data\GeoPoint;
use App\Enums\DriverAvailability;
use App\Events\Realtime\DriverLocationUpdated;
use App\Exceptions\DriverStatusException;
use App\Models\DriverProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One position report from the driver app (the REST fallback of the Node service's own GEOADD): checked against the driver's
 * state, written to the Redis geo set with its time, appended to MongoDB for history (after the response, best effort) and
 * announced as `driver.location_updated`. The driver is always the signed-in one; nothing in the request names a driver.
 */
final class DriverLocationService
{
    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly DriverLocationStore $store,
        private readonly DriverPingHistory $history,
    ) {}

    /**
     * @throws DriverStatusException not verified / expired documents (403), offline (409), Redis unavailable (503)
     */
    public function record(User $user, GeoPoint $point, ?int $heading, ?float $speed, ?float $accuracy): CarbonImmutable
    {
        $profile = DriverProfile::query()->where('user_id', $user->getKey())->first() ?? throw DriverStatusException::notVerified();

        $this->eligibility->assertVerified($profile);

        // A driver on a trip keeps reporting: "online" here means online or on a trip.
        if ($profile->availability === DriverAvailability::Offline) {
            throw DriverStatusException::offline();
        }

        $at = CarbonImmutable::now();

        try {
            $this->store->record($profile->user_id, $point, $at);
        } catch (Throwable $e) {
            // Never log the position: it is personal data.
            Log::error('A driver position could not be written to Redis.', ['driver_user_id' => $profile->user_id, 'exception' => $e::class]);

            throw DriverStatusException::locationUnavailable();
        }

        // After the response is sent: the driver does not wait for the history write.
        defer(fn () => $this->history->append($profile->user_id, $profile->getKey(), $point->latitude, $point->longitude, $heading, $speed, $accuracy, $at));
        DriverLocationUpdated::dispatch($profile->user_id, $point->latitude, $point->longitude, $heading, $at);

        return $at;
    }
}
