<?php

namespace App\Services\Drivers;

use App\Enums\TripStatus;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\Zone;
use Carbon\CarbonImmutable;

/**
 * What a driver earned today, in the last 7 days and in the last 30 days, for the driver app's earnings screen. Added up from the completed
 * trips as stored (fare, commission, driver earnings: the split taken when each trip was completed), so it needs no ledger. A "day" is a
 * calendar day in the timezone of the driver's home zone (UTC without one), so "today" turns over at the driver's midnight, not the server's.
 * Trips priced in another currency than the home zone's are left out. Cash commission owed to the platform is not tracked here (P11-T3).
 */
final class DriverEarningsSummary
{
    private const WEEK_DAYS = 7;

    private const MONTH_DAYS = 30;

    /**
     * @return array{currency: string, today: array<string, int>, week: array<string, int>, month: array<string, int>}
     */
    public function for(DriverProfile $driver): array
    {
        $zone = $driver->homeZone;
        $currency = $zone?->currency ?? config('taxikosmos.base_currency');
        [$todayStart, $tomorrow] = $zone instanceof Zone ? $zone->dayBounds() : $this->utcDay();

        return [
            'currency' => $currency,
            'today' => $this->total($driver, $currency, $todayStart, $tomorrow),
            'week' => $this->total($driver, $currency, $todayStart->subDays(self::WEEK_DAYS - 1), $tomorrow),
            'month' => $this->total($driver, $currency, $todayStart->subDays(self::MONTH_DAYS - 1), $tomorrow),
        ];
    }

    /**
     * @return array{trips: int, fare: int, commission: int, earnings: int}
     */
    private function total(DriverProfile $driver, string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = Trip::query()
            ->where('driver_id', $driver->getKey())
            ->where('status', TripStatus::Completed->value)
            ->where('fare_currency', $currency)
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $to)
            ->selectRaw('count(*) as trips, coalesce(sum(fare_amount), 0) as fare, coalesce(sum(commission_amount), 0) as commission, coalesce(sum(driver_earnings_amount), 0) as earnings')
            ->first();

        return ['trips' => (int) $row->trips, 'fare' => (int) $row->fare, 'commission' => (int) $row->commission, 'earnings' => (int) $row->earnings];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function utcDay(): array
    {
        $start = CarbonImmutable::now('UTC')->startOfDay();

        return [$start, $start->addDay()];
    }
}
