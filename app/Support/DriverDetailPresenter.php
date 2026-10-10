<?php

namespace App\Support;

use App\Models\DriverProfile;

/**
 * The Drivers → detail page props for a driver that exists in the database, in the same shape as
 * the fixture rows (resources/js/types DriverDetail). Everything that has a table today is real;
 * trips, ratings and earnings stay empty until their tasks (P7, P11-T2) build them. Nothing from
 * the fixtures is mixed in, so a real driver never shows another driver's vehicles or trips.
 */
final class DriverDetailPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DriverProfile $profile): array
    {
        $profile->loadMissing(['user', 'vehicles', 'bankAccounts', 'primaryVehicle', 'documents.reviewer:id,name']);

        $zero = ['amount' => 0, 'currency' => config('taxikosmos.base_currency')];
        $primary = $profile->primaryVehicle;

        return [
            'id' => $profile->id,
            'code' => sprintf('D-%04d', $profile->id),
            'name' => $profile->user->name,
            'phone' => $profile->user->phone ?? '—',
            'vehicle' => $primary === null ? null : ['label' => "{$primary->make} {$primary->model}", 'year' => $primary->year],
            'verification_status' => $profile->verification_status->value,
            'rating' => $profile->rating_avg,
            'trips_count' => 0,
            'earnings' => $zero,
            'documents' => $profile->documents->sortBy('id')->map(DriverDocumentPresenter::present(...))->values()->all(),
            'vehicles' => $profile->vehicles->sortBy('id')->map(fn ($v) => [
                'id' => $v->id, 'make' => $v->make, 'model' => $v->model, 'year' => $v->year, 'color' => $v->color,
                'plate_number' => $v->plate_number, 'vehicle_class' => $v->vehicle_class->value,
                'status' => $v->status->value, 'is_primary' => $v->is_primary,
            ])->values()->all(),
            'earnings_detail' => [
                'balance' => $zero,
                'debt_limit_exceeded' => false,
                'summary' => ['week' => $zero, 'month' => $zero, 'all_time' => $zero],
                'ledger' => [],
            ],
            'trip_history' => [],
            'bank_accounts' => $profile->bankAccounts->sortBy('id')->map(fn ($a) => [
                'id' => $a->id, 'bank_name' => $a->bank_name, 'account_last4' => $a->account_last4, 'is_default' => $a->is_default,
            ])->values()->all(),
        ];
    }
}
