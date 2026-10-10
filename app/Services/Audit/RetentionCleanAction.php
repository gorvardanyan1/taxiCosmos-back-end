<?php

namespace App\Services\Audit;

use App\Models\Audit\ActivityLogEntry;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * Deletes audit entries past the retention period (ACTIVITYLOG_RETENTION_DAYS, default 2 years).
 * `activitylog:clean --days=1` cannot shorten it: the configured retention is the floor.
 */
class RetentionCleanAction extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        $days = max($maxAgeInDays, (int) config('activitylog.clean_after_days'));

        return ActivityLogEntry::query()->purgeOlderThan(Carbon::now()->subDays($days), $logName);
    }
}
