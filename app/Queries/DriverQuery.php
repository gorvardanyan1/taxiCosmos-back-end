<?php

namespace App\Queries;

use App\Enums\DriverAvailability;
use App\Enums\DriverVerificationStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * The Drivers list. URL contract (like every admin list): filter[search] (name or email contains, driver
 * code D-0042, an exact phone, an exact licence number), filter[verification_status], filter[availability]
 * (online / offline / on_trip), filter[zone] (home zone id), filter[vehicle_class] (the driver has a vehicle
 * of that class), filter[license_number] (exact, through the blind index), filter[documents_expiring]
 * (a document expires within N days, 1-365), sort=name|trips_count|rating (prefix - for descending).
 * Unknown filters, sorts and malformed values are 400.
 *
 * Every row carries its completed-trip count and earnings as sub-selects, so the list issues the same
 * queries however many drivers it shows. Earnings are the drivers' share of completed trips in the
 * currency of the driver's home zone (the base currency without one) until the earnings ledger (P11-T2).
 */
final class DriverQuery
{
    private const MAX_EXPIRY_DAYS = 365;

    /**
     * @return QueryBuilder<DriverProfile>
     */
    public static function for(Request $request): QueryBuilder
    {
        $base = config('taxikosmos.base_currency');
        $currency = "COALESCE((SELECT z.currency FROM zones z WHERE z.id = driver_profiles.home_zone_id), '{$base}')";

        $query = DriverProfile::query()
            ->with(['user' => fn ($user) => $user->withTrashed(), 'primaryVehicle'])
            ->select('driver_profiles.*')
            ->selectSub(Trip::query()->selectRaw('count(*)')->whereColumn('trips.driver_id', 'driver_profiles.id')->where('trips.status', TripStatus::Completed->value), 'trips_done')
            ->selectSub(Trip::query()->selectRaw('COALESCE(SUM(trips.driver_earnings_amount), 0)')->whereColumn('trips.driver_id', 'driver_profiles.id')->where('trips.status', TripStatus::Completed->value)->whereRaw("trips.fare_currency = {$currency}"), 'earnings_total')
            ->selectRaw("{$currency} as earnings_currency");

        return QueryBuilder::for($query, $request)
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $q, $value) => self::search($q, is_array($value) ? implode(',', $value) : (string) $value)),
                AllowedFilter::callback('verification_status', fn (Builder $q, $value) => $q->where('verification_status', (DriverVerificationStatus::tryFrom(FilterInput::single($value, 'verification_status')) ?? abort(400, __('Unknown verification status.')))->value)),
                AllowedFilter::callback('availability', fn (Builder $q, $value) => $q->where('availability', (DriverAvailability::tryFrom(FilterInput::single($value, 'availability')) ?? abort(400, __('Unknown availability.')))->value)),
                AllowedFilter::callback('zone', fn (Builder $q, $value) => $q->where('home_zone_id', FilterInput::integer($value, 'zone'))),
                AllowedFilter::callback('vehicle_class', fn (Builder $q, $value) => $q->whereHas('vehicles', fn (Builder $v) => $v->where('vehicle_class', (VehicleClass::tryFrom(FilterInput::single($value, 'vehicle_class')) ?? abort(400, __('Unknown vehicle class.')))->value))),
                AllowedFilter::callback('license_number', fn (Builder $q, $value) => $q->whereLicenseNumber(FilterInput::single($value, 'license_number'))),
                AllowedFilter::callback('documents_expiring', function (Builder $q, $value) {
                    $days = FilterInput::integer($value, 'documents_expiring');
                    $days <= self::MAX_EXPIRY_DAYS || abort(400, __('Invalid :filter filter.', ['filter' => 'documents_expiring']));

                    $q->whereHas('documents', fn (Builder $d) => $d->whereBetween('expires_at', [now()->toDateString(), now()->addDays($days)->toDateString()]));
                }),
            ])
            ->allowedSorts(
                AllowedSort::custom('name', new class implements Sort
                {
                    public function __invoke(Builder $query, bool $descending, string $property): void
                    {
                        $query->orderBy(User::query()->withTrashed()->select('name')->whereColumn('users.id', 'driver_profiles.user_id'), $descending ? 'desc' : 'asc');
                    }
                }),
                AllowedSort::custom('trips_count', new class implements Sort
                {
                    public function __invoke(Builder $query, bool $descending, string $property): void
                    {
                        $query->orderBy('trips_done', $descending ? 'desc' : 'asc');
                    }
                }),
                AllowedSort::custom('rating', new class implements Sort
                {
                    public function __invoke(Builder $query, bool $descending, string $property): void
                    {
                        $query->orderByRaw('rating_avg '.($descending ? 'DESC' : 'ASC').' NULLS LAST');
                    }
                }),
            )
            ->defaultSort('-id')
            ->orderByDesc('driver_profiles.id');
    }

    /**
     * @param  Builder<DriverProfile>  $query
     */
    private static function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';
        $phone = E164PhoneNumber::tryNormalize($term);

        $query->where(function (Builder $q) use ($like, $phone, $term) {
            $q->whereHas('user', function (Builder $user) use ($like, $phone) {
                $user->where(function (Builder $u) use ($like, $phone) {
                    $u->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]);

                    // An exact phone, in any way of writing it, through the blind index (the column is encrypted).
                    $phone !== null && $u->orWhere('phone_hash', app(BlindIndex::class)->hash($phone));
                });
            });

            // The driver code, with or without the dash and leading zeros: D-0042, d42, 42.
            if (preg_match('/^d?-?0*(\d{1,18})$/i', $term, $m) === 1) {
                $q->orWhere('driver_profiles.id', (int) $m[1]);
            }

            // An exact licence number (case and spaces ignored), through the blind index.
            $q->orWhere(fn (Builder $licence) => $licence->whereLicenseNumber($term));
        });
    }
}
