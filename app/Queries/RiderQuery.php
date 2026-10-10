<?php

namespace App\Queries;

use App\Enums\UserStatus;
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
 * The Riders list: users with rider capability. URL contract (like every admin list):
 * filter[search] (name or email contains, rider code, or an exact phone through the blind index),
 * filter[status], filter[registered_from] / filter[registered_to] (inclusive days in the admin's
 * timezone), sort=registered_at|name|trips_count (prefix - for descending). Unknown filters,
 * sorts and malformed values are 400.
 */
final class RiderQuery
{
    /** Rider codes look like R-00042. */
    public static function code(int $id): string
    {
        return sprintf('R-%05d', $id);
    }

    /**
     * Trips per rider. There is no trips table yet (P2-T4), so every rider has none; the list,
     * the sort and the detail page already go through this one place.
     */
    public static function tripsCountSql(): string
    {
        return '(SELECT 0)';
    }

    /**
     * @return QueryBuilder<User>
     */
    public static function for(Request $request, string $timezone): QueryBuilder
    {
        return QueryBuilder::for(User::query()->where('is_rider', true), $request)
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $query, $value) => self::search($query, is_array($value) ? implode(',', $value) : (string) $value)),
                AllowedFilter::callback('status', fn (Builder $query, $value) => $query->where('status', (UserStatus::tryFrom(FilterInput::single($value, 'status')) ?? abort(400, 'Unknown status.'))->value)),
                AllowedFilter::callback('registered_from', fn (Builder $query, $value) => $query->where('created_at', '>=', FilterInput::day($value, $timezone)->startOfDay()->utc())),
                AllowedFilter::callback('registered_to', fn (Builder $query, $value) => $query->where('created_at', '<=', FilterInput::day($value, $timezone)->endOfDay()->utc())),
            ])
            ->allowedSorts(
                AllowedSort::field('registered_at', 'created_at'),
                AllowedSort::field('name'),
                AllowedSort::custom('trips_count', new class implements Sort
                {
                    public function __invoke(Builder $query, bool $descending, string $property): void
                    {
                        $query->orderByRaw(RiderQuery::tripsCountSql().($descending ? ' DESC' : ' ASC'));
                    }
                }),
            )
            ->defaultSort('-created_at', '-id')
            // Riders that tie on the chosen sort (same name, no trips yet) keep a stable, newest-first order.
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<User>  $query
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
            $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]);

            // An exact phone, in any way of writing it, through the blind index (the column is encrypted).
            if ($phone !== null) {
                $q->orWhere('phone_hash', app(BlindIndex::class)->hash($phone));
            }

            // The rider code, with or without the dash and leading zeros: R-00042, r42, 42.
            if (preg_match('/^r?-?0*(\d{1,18})$/i', $term, $m) === 1) {
                $q->orWhere('id', (int) $m[1]);
            }
        });
    }
}
