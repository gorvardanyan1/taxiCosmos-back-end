<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Builder;

/**
 * Query builder of the audit log: rows can be read and created, never changed or removed through
 * it. The only way out is purgeOlderThan(), used by the retention job.
 *
 * @extends Builder<ActivityLogEntry>
 */
class AuditEntryBuilder extends Builder
{
    public function update(array $values): never
    {
        throw ActivityLogEntry::immutable();
    }

    public function delete(): never
    {
        throw ActivityLogEntry::immutable();
    }

    public function forceDelete(): never
    {
        throw ActivityLogEntry::immutable();
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw ActivityLogEntry::immutable();
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw ActivityLogEntry::immutable();
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw ActivityLogEntry::immutable();
    }

    /**
     * Retention: removes entries created before the cut-off. Not reachable from any route.
     */
    public function purgeOlderThan(\DateTimeInterface $cutOff, ?string $logName = null): int
    {
        $query = $this->getQuery()->getConnection()->table($this->getModel()->getTable())->where('created_at', '<', $cutOff);

        if ($logName !== null) {
            $query->where('log_name', $logName);
        }

        return $query->delete();
    }
}
