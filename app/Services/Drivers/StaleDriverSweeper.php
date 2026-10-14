<?php

namespace App\Services\Drivers;

use App\Enums\DriverAvailability;
use App\Models\DriverProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping for driver positions (scheduled every minute):
 *  1. an online driver whose last ping is older than the limit (or who never pinged since going online) is marked offline;
 *  2. a position left in Redis by a driver who is not online or on a trip is pruned.
 * A driver on a trip is never touched here: a lost GPS signal must not end the trip's tracking. Drivers are changed through
 * the model, so `driver.availability_changed` is published like for any other change.
 */
final class StaleDriverSweeper
{
    private const CHUNK = 200;

    public function __construct(private readonly DriverLocationStore $store) {}

    /**
     * @return array{marked_offline: int, pruned: int}
     */
    public function sweep(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMinutes((int) config('taxikosmos.driver_location.stale_after_minutes'))->getTimestamp();
        $marked = 0;
        $pruned = 0;

        // 1. Positions that have gone stale (every driver with a ping at or before the cutoff).
        foreach (array_chunk($this->store->pingedAtOrBefore($cutoff), self::CHUNK) as $ids) {
            $profiles = DriverProfile::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

            foreach ($ids as $id) {
                $profile = $profiles->get($id);

                if ($profile === null || $profile->availability === DriverAvailability::Offline) {
                    $pruned += (int) $this->store->removeQuietly($id);
                } else {
                    // Only an online driver changes; a driver on a trip keeps their position (checked again under the lock).
                    $marked += (int) $this->markOfflineIfStale($id, $cutoff);
                }
            }
        }

        // 2. Online drivers who never pinged since going online (they have no entry above).
        DriverProfile::query()
            ->where('availability', DriverAvailability::Online->value)
            ->where('last_online_at', '<=', $now->subMinutes((int) config('taxikosmos.driver_location.stale_after_minutes')))
            ->chunkById(self::CHUNK, function ($profiles) use ($cutoff, &$marked) {
                $seen = $this->store->lastSeen($profiles->pluck('user_id')->all());

                foreach ($profiles as $profile) {
                    if ($seen[$profile->user_id] === null && $this->markOfflineIfStale($profile->user_id, $cutoff)) {
                        $marked++;
                    }
                }
            });

        return ['marked_offline' => $marked, 'pruned' => $pruned];
    }

    /** Marks the driver offline unless they pinged (or went offline or on a trip) since the list was read. */
    private function markOfflineIfStale(int $driverUserId, int $cutoff): bool
    {
        $changed = DB::transaction(function () use ($driverUserId, $cutoff) {
            $profile = DriverProfile::query()->where('user_id', $driverUserId)->lockForUpdate()->first();

            if ($profile === null || $profile->availability !== DriverAvailability::Online) {
                return false;
            }

            $lastSeen = $this->store->lastSeen([$driverUserId])[$driverUserId];
            if ($lastSeen !== null && $lastSeen > $cutoff) {
                return false;
            }

            // Went online only moments ago: an old position is left from an earlier session (it was not cleared), and the
            // driver has not had the time yet to send the first ping.
            if ($profile->last_online_at !== null && $profile->last_online_at->getTimestamp() > $cutoff) {
                return false;
            }

            $profile->forceFill(['availability' => DriverAvailability::Offline])->save();

            return true;
        });

        if ($changed) {
            $this->store->removeQuietly($driverUserId);
        }

        return $changed;
    }
}
