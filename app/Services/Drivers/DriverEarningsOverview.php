<?php

namespace App\Services\Drivers;

use App\Enums\TripStatus;
use App\Models\DriverProfile;
use App\Models\Trip;

/**
 * The Earnings tab until the earnings ledger exists (P11-T2): what the driver earned from completed
 * trips, as stored on each trip (driver_earnings_amount, the split taken when the trip was completed).
 * Amounts are in the currency of the driver's home zone (the base currency without one); trips priced in
 * another currency are left out of the totals. There are no payouts, cash settlements or adjustments yet,
 * so the balance is simply the total earned, and the page says so (`ledger_pending`).
 */
final class DriverEarningsOverview
{
    private const LEDGER_LIMIT = 50;

    private const WEEK_DAYS = 7;

    private const MONTH_DAYS = 30;

    /**
     * @return array{balance: array<string, mixed>, debt_limit_exceeded: bool, summary: array<string, array<string, mixed>>, ledger: list<array<string, mixed>>, ledger_pending: bool}
     */
    public function for(DriverProfile $driver): array
    {
        $currency = $driver->homeZone?->currency ?? config('taxikosmos.base_currency');
        $completed = fn () => Trip::query()->where('driver_id', $driver->id)->where('status', TripStatus::Completed->value)->where('fare_currency', $currency);
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $currency];

        $total = (int) $completed()->sum('driver_earnings_amount');

        return [
            'balance' => $money($total),
            'debt_limit_exceeded' => false,
            'summary' => [
                'week' => $money((int) $completed()->where('completed_at', '>=', now()->subDays(self::WEEK_DAYS))->sum('driver_earnings_amount')),
                'month' => $money((int) $completed()->where('completed_at', '>=', now()->subDays(self::MONTH_DAYS))->sum('driver_earnings_amount')),
                'all_time' => $money($total),
            ],
            'ledger' => $completed()->orderByDesc('completed_at')->orderByDesc('id')->limit(self::LEDGER_LIMIT)->get(['id', 'public_code', 'driver_earnings_amount', 'fare_currency', 'completed_at'])
                ->map(fn (Trip $trip) => [
                    'id' => $trip->id, 'type' => 'trip_earning', 'reference' => $trip->public_code,
                    'amount' => ['amount' => (int) $trip->driver_earnings_amount, 'currency' => $trip->fare_currency],
                    'occurred_at' => $trip->completed_at->toIso8601String(),
                ])->all(),
            'ledger_pending' => true,
        ];
    }
}
