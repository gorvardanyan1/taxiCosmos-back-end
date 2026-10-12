<?php

namespace App\Queries;

use App\Enums\VehicleClass;
use App\Models\CommissionRule;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * The Commission page's list: the rules in force now (past versions are in a rule's history).
 * URL contract: filter[search] (zone name or code contains), filter[vehicle_class] (a class, or "all"
 * for the zone-wide rules), filter[zone] (zone id), sort=effective_from|zone (prefix - for descending).
 * Unknown filters, sorts and malformed values are 400.
 */
final class CommissionRuleQuery
{
    public const ALL_CLASSES = 'all';

    /**
     * @return QueryBuilder<CommissionRule>
     */
    public static function for(Request $request): QueryBuilder
    {
        return QueryBuilder::for(CommissionRule::query()->with('zone:id,name,code,currency')->effectiveAt(now()), $request)
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    $term = trim(is_array($value) ? implode(',', $value) : (string) $value);
                    $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';

                    $term === '' || $query->whereHas('zone', fn (Builder $zone) => $zone->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like]));
                }),
                AllowedFilter::callback('vehicle_class', function (Builder $query, $value) {
                    $class = FilterInput::single($value, 'vehicle_class');

                    $class === self::ALL_CLASSES
                        ? $query->whereNull('vehicle_class')
                        : $query->where('vehicle_class', (VehicleClass::tryFrom($class) ?? abort(400, __('Unknown vehicle class.')))->value);
                }),
                AllowedFilter::callback('zone', fn (Builder $query, $value) => $query->where('zone_id', FilterInput::integer($value, 'zone'))),
            ])
            ->allowedSorts(
                AllowedSort::field('effective_from'),
                AllowedSort::custom('zone', new class implements Sort
                {
                    public function __invoke(Builder $query, bool $descending, string $property): void
                    {
                        $query->orderBy(Zone::query()->select('name')->whereColumn('zones.id', 'commission_rules.zone_id'), $descending ? 'desc' : 'asc');
                    }
                }),
            )
            ->defaultSort('-effective_from', '-id')
            ->orderByDesc('id');
    }
}
