<?php

namespace App\Queries;

use App\Enums\AuditTargetType;
use App\Models\Audit\ActivityLogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The Activity Log list and its CSV export share this query, so "the current filter" means the
 * same rows in both. URL contract (like every admin list): filter[search], filter[actor] (admin
 * id), filter[action] (exact name or a prefix such as "driver.document"), filter[target_type],
 * filter[target_id], filter[from] and filter[to] (dates, inclusive, in the admin's timezone),
 * sort=occurred_at|-occurred_at. Unknown filters are rejected with 400 by the query builder.
 */
final class AuditLogQuery
{
    public const SORTS = ['occurred_at'];

    /**
     * @return QueryBuilder<ActivityLogEntry>
     */
    public static function for(Request $request, string $timezone): QueryBuilder
    {
        return QueryBuilder::for(ActivityLogEntry::query(), $request)
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    // The query builder splits "a, b" at commas; put the text back together.
                    $term = '%'.addcslashes(mb_strtolower(is_array($value) ? implode(',', $value) : (string) $value), '\\%_').'%';
                    $query->where(fn (Builder $q) => $q
                        ->whereRaw('lower(description) like ?', [$term])
                        ->orWhereRaw("lower(properties->>'reason') like ?", [$term])
                        ->orWhereRaw("lower(properties->>'actor_name') like ?", [$term])
                        ->orWhereRaw("lower(properties->>'target_label') like ?", [$term]));
                }),
                AllowedFilter::callback('actor', fn (Builder $query, $value) => $query->where('causer_id', FilterInput::integer($value, 'actor'))),
                AllowedFilter::callback('action', function (Builder $query, $value) {
                    $name = FilterInput::single($value, 'action');
                    $query->where(fn (Builder $q) => $q
                        ->where('description', $name)
                        ->orWhere('description', 'like', addcslashes($name, '\\%_').'.%'));
                }),
                AllowedFilter::callback('target_type', function (Builder $query, $value) {
                    $type = AuditTargetType::tryFrom(FilterInput::single($value, 'target_type')) ?? abort(400, 'Unknown target type.');
                    $query->where('subject_type', $type->modelClass());
                }),
                AllowedFilter::callback('target_id', fn (Builder $query, $value) => $query->where('subject_id', FilterInput::integer($value, 'target_id'))),
                AllowedFilter::callback('from', fn (Builder $query, $value) => $query->where('created_at', '>=', FilterInput::day($value, $timezone)->startOfDay()->utc())),
                AllowedFilter::callback('to', fn (Builder $query, $value) => $query->where('created_at', '<=', FilterInput::day($value, $timezone)->endOfDay()->utc())),
            ])
            ->allowedSorts(AllowedSort::field('occurred_at', 'created_at'))
            ->defaultSort('-created_at', '-id');
    }
}
