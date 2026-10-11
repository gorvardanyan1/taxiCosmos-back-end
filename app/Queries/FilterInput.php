<?php

namespace App\Queries;

use Carbon\CarbonImmutable;

/**
 * Reads single-value filters from a list URL. The query builder turns "a,b" and "x[]=1" into
 * arrays; every filter except free-text search takes exactly one value and answers 400 otherwise,
 * so crafted URLs never reach a cast or the database.
 */
final class FilterInput
{
    private const MIN_YEAR = 1970;

    private const MAX_YEAR = 2100;

    public static function single(mixed $value, string $filter): string
    {
        return is_array($value) ? abort(400, "The {$filter} filter takes a single value.") : (string) $value;
    }

    public static function integer(mixed $value, string $filter): int
    {
        return filter_var(self::single($value, $filter), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: abort(400, "Invalid {$filter} filter.");
    }

    /**
     * A calendar day (YYYY-MM-DD, years 1970-2100: PostgreSQL has no year 0) in the given timezone.
     */
    public static function day(mixed $value, string $timezone): CarbonImmutable
    {
        $value = self::single($value, 'date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) : false;
        $valid = $date !== false && $date->format('Y-m-d') === $value && $date->year >= self::MIN_YEAR && $date->year <= self::MAX_YEAR;

        return $valid ? $date : abort(400, 'Dates must look like 2026-10-05 (years '.self::MIN_YEAR.'-'.self::MAX_YEAR.').');
    }
}
