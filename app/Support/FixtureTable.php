<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paginates fixture rows with the same URL contract the real lists will use with
 * spatie/laravel-query-builder (filter[search], filter[<field>], sort=<field>|-<field>,
 * page, per_page), so pages, deep links and back/forward keep working when a domain
 * task swaps the fixture for an Eloquent query. Unknown filters/sorts are rejected with
 * 400, like the query builder does.
 */
final class FixtureTable
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $searchable  keys matched case-insensitively by filter[search]
     * @param  list<string>  $filterable  keys matched exactly by filter[<key>]
     * @param  list<string>  $sortable
     */
    public static function paginate(
        Request $request,
        array $rows,
        array $searchable = [],
        array $filterable = [],
        array $sortable = [],
        ?string $defaultSort = null,
    ): LengthAwarePaginator {
        $filters = self::filters($request, $filterable);
        $sort = self::sort($request, $sortable) ?? $defaultSort;
        $perPage = self::perPage($request);

        $collection = collect($rows);

        if (isset($filters['search'])) {
            $term = mb_strtolower($filters['search']);
            $collection = $collection->filter(fn (array $row) => collect($searchable)->contains(
                fn (string $key) => str_contains(mb_strtolower((string) data_get($row, $key)), $term),
            ));
        }

        foreach (array_diff_key($filters, ['search' => true]) as $key => $value) {
            $collection = $collection->filter(fn (array $row) => (string) data_get($row, $key) === $value);
        }

        if ($sort !== null) {
            $field = ltrim($sort, '-');
            $collection = $collection->sortBy(function (array $row) use ($field) {
                $value = data_get($row, $field);

                // Numbers compare numerically (negative money too); text compares case-insensitively.
                return is_string($value) ? mb_strtolower($value) : $value;
            }, SORT_REGULAR, str_starts_with($sort, '-'));
        }

        $collection = $collection->values();
        $page = max(1, (int) $request->query('page', 1));

        return (new LengthAwarePaginator(
            $collection->forPage($page, $perPage)->values(),
            $collection->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'page'],
        ))->appends(Arr::except($request->query(), 'page'));
    }

    /**
     * The active filters echoed back to the page (for inputs and deep links).
     *
     * @param  list<string>  $filterable
     * @return array<string, string>
     */
    public static function filters(Request $request, array $filterable = []): array
    {
        $raw = $request->query('filter', []);
        abort_unless(is_array($raw), Response::HTTP_BAD_REQUEST, 'Invalid filter query.');

        $allowed = ['search', ...$filterable];
        $unknown = array_diff(array_keys($raw), $allowed);
        abort_if($unknown !== [], Response::HTTP_BAD_REQUEST, 'Requested filter(s) `'.implode(', ', $unknown).'` are not allowed.');

        return collect($raw)
            ->map(fn ($value) => is_scalar($value) ? mb_substr(trim((string) $value), 0, 100) : abort(Response::HTTP_BAD_REQUEST, 'Invalid filter value.'))
            ->filter(fn (string $value) => $value !== '')
            ->all();
    }

    /**
     * @param  list<string>  $sortable
     */
    public static function sort(Request $request, array $sortable): ?string
    {
        $sort = $request->query('sort');

        if ($sort === null || $sort === '') {
            return null;
        }

        abort_unless(is_string($sort) && in_array(ltrim($sort, '-'), $sortable, true), Response::HTTP_BAD_REQUEST, 'Requested sort is not allowed.');

        return $sort;
    }

    private static function perPage(Request $request): int
    {
        $options = config('taxikosmos.admin.per_page_options');
        $requested = (int) $request->query('per_page', $options[0]);

        return in_array($requested, $options, true) ? $requested : $options[0];
    }
}
