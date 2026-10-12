<?php

namespace App\Support;

use App\Models\DriverProfile;
use App\Models\Trip;
use App\Services\Drivers\DriverEarningsOverview;

/**
 * Drivers as the admin list and detail pages show them. Everything has a table now: documents, vehicles,
 * bank accounts (masked: last four digits only), trips (from the trips table) and earnings (from completed
 * trips until the earnings ledger, P11-T2). Ratings stay empty until P7-T3.
 */
final class DriverDetailPresenter
{
    private const TRIP_HISTORY_LIMIT = 50;

    /**
     * One list row. Expects `user` and `primaryVehicle` loaded and, from DriverQuery, `trips_done`,
     * `earnings_total` and `earnings_currency`; without them the counts fall back to zero.
     *
     * @return array<string, mixed>
     */
    public static function row(DriverProfile $profile): array
    {
        $primary = $profile->primaryVehicle;
        // The driver's records outlive their account (account deletion keeps trips and documents
        // reviewable), so a deleted user shows a placeholder and no personal data.
        $deleted = $profile->user->trashed();

        return [
            'id' => $profile->id,
            'code' => sprintf('D-%04d', $profile->id),
            'name' => $deleted ? __('Deleted account') : $profile->user->name,
            'phone' => $deleted ? '—' : ($profile->user->phone ?? '—'),
            'vehicle' => $primary === null ? null : ['label' => "{$primary->make} {$primary->model}", 'year' => $primary->year],
            'verification_status' => $profile->verification_status->value,
            'availability' => $profile->availability->value,
            'status' => $profile->user->status->value,
            'rating' => $profile->rating_avg,
            'trips_count' => (int) ($profile->getAttribute('trips_done') ?? 0),
            'earnings' => [
                'amount' => (int) ($profile->getAttribute('earnings_total') ?? 0),
                'currency' => $profile->getAttribute('earnings_currency') ?? config('taxikosmos.base_currency'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(DriverProfile $profile): array
    {
        $profile->loadMissing(['user' => fn ($query) => $query->withTrashed(), 'vehicles', 'bankAccounts', 'primaryVehicle', 'documents.reviewer:id,name', 'homeZone:id,name,currency']);
        $overview = app(DriverEarningsOverview::class)->for($profile);
        $deleted = $profile->user->trashed();

        return [
            ...self::row($profile),
            'trips_count' => $profile->trips()->where('status', 'completed')->count(),
            'earnings' => $overview['summary']['all_time'],
            // The edit form needs the raw values, not the "—" placeholders. The licence number is never sent: only its last four.
            'raw' => [
                'name' => $deleted ? null : $profile->user->name, 'email' => $deleted ? null : $profile->user->email, 'phone' => $deleted ? null : $profile->user->phone,
                'license_expiry' => $profile->license_expiry?->toDateString(), 'home_zone_id' => $profile->home_zone_id,
            ],
            'license_last4' => $deleted ? null : substr((string) $profile->license_number, -4),
            'home_zone' => $profile->homeZone?->name,
            'suspension_reason' => $profile->user->status->value === 'suspended' ? $profile->user->suspension_reason : null,
            'documents' => $profile->documents->sortBy('id')->map(DriverDocumentPresenter::present(...))->values()->all(),
            'vehicles' => $profile->vehicles->sortBy('id')->map(fn ($v) => [
                'id' => $v->id, 'make' => $v->make, 'model' => $v->model, 'year' => $v->year, 'color' => $v->color,
                'plate_number' => $v->plate_number, 'vehicle_class' => $v->vehicle_class->value,
                'status' => $v->status->value, 'is_primary' => $v->is_primary,
            ])->values()->all(),
            'earnings_detail' => $overview,
            'trip_history' => Trip::query()->where('driver_id', $profile->id)->orderByDesc('requested_at')->orderByDesc('id')->limit(self::TRIP_HISTORY_LIMIT)->get()
                ->map(fn (Trip $trip) => [
                    'id' => $trip->id, 'code' => $trip->public_code, 'route' => "{$trip->pickup_address} → {$trip->dropoff_address}",
                    'fare' => ['amount' => (int) ($trip->fare_amount ?? $trip->estimated_fare_amount), 'currency' => $trip->fare_currency],
                    'status' => $trip->status->value, 'rating' => null, 'occurred_at' => $trip->requested_at->toIso8601String(),
                ])->all(),
            // Masked: the full number is only ever revealed through BankAccountReveal.
            'bank_accounts' => $profile->bankAccounts->sortBy('id')->map(fn ($a) => [
                'id' => $a->id, 'bank_name' => $a->bank_name, 'account_last4' => $a->account_last4, 'is_default' => $a->is_default,
            ])->values()->all(),
        ];
    }
}
