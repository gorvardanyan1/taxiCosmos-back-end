<?php

namespace App\Queries;

use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The Support Tickets list. URL contract: filter[status], filter[category], filter[priority], filter[assignee]
 * (me, unassigned or an admin id), filter[search] (ticket code, subject or requester name). Unknown filters and malformed
 * values are 400. Newest activity first.
 */
final class SupportTicketQuery
{
    /**
     * @return QueryBuilder<SupportTicket>
     */
    public static function for(Request $request): QueryBuilder
    {
        return QueryBuilder::for(SupportTicket::query()->with(['requester.driverProfile', 'assignee']), $request)
            ->allowedFilters(
                AllowedFilter::callback('status', fn (Builder $query, $value) => $query->where('status', (SupportStatus::tryFrom(FilterInput::single($value, 'status')) ?? abort(400, __('Unknown status.')))->value)),
                AllowedFilter::callback('category', fn (Builder $query, $value) => $query->where('category', (SupportCategory::tryFrom(FilterInput::single($value, 'category')) ?? abort(400, __('Unknown category.')))->value)),
                AllowedFilter::callback('priority', fn (Builder $query, $value) => $query->where('priority', (SupportPriority::tryFrom(FilterInput::single($value, 'priority')) ?? abort(400, __('Unknown priority.')))->value)),
                AllowedFilter::callback('assignee', fn (Builder $query, $value) => self::assignee($query, FilterInput::single($value, 'assignee'), $request)),
                AllowedFilter::callback('search', fn (Builder $query, $value) => self::search($query, is_array($value) ? implode(',', $value) : (string) $value)),
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<SupportTicket>  $query
     */
    private static function assignee(Builder $query, string $value, Request $request): void
    {
        match (true) {
            $value === 'unassigned' => $query->whereNull('assigned_to'),
            $value === 'me' => $query->where('assigned_to', $request->user() instanceof User ? $request->user()->getKey() : 0),
            default => $query->where('assigned_to', FilterInput::integer($value, 'assignee')),
        };
    }

    /**
     * @param  Builder<SupportTicket>  $query
     */
    private static function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';

        $query->where(function (Builder $q) use ($like, $term) {
            $q->whereRaw('lower(subject) like ?', [$like])
                ->orWhereRaw('lower(code) like ?', [$like])
                ->orWhereHas('requester', fn (Builder $user) => $user->whereRaw('lower(name) like ?', [$like]));

            // The code with or without the prefix and leading zeros: TKT-00042, tkt42, 42.
            if (preg_match('/^(?:tkt-?)?0*(\d{1,18})$/i', $term, $m) === 1) {
                $q->orWhere('id', (int) $m[1]);
            }
        });
    }
}
