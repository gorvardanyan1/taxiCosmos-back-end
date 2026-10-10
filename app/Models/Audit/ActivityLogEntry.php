<?php

namespace App\Models\Audit;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * An audit log row. Append-only from the application: every Eloquent write to an existing row
 * (save, update, delete, touch, destroy) goes through AuditEntryBuilder, which refuses it.
 * Retention removes old rows through AuditEntryBuilder::purgeOlderThan() only.
 */
class ActivityLogEntry extends Activity
{
    public static function immutable(): LogicException
    {
        return new LogicException('Audit log entries cannot be changed or deleted.');
    }

    public function newEloquentBuilder($query): AuditEntryBuilder
    {
        return new AuditEntryBuilder($query);
    }
}
