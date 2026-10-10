<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Tests\Feature\Admin\AdminTestCase;

abstract class AuditTestCase extends AdminTestCase
{
    protected function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    /**
     * Writes an entry at a given time (the log row is stamped with "now").
     */
    protected function entryAt(string $when, User $actor, string $action = 'driver.document.approved', array $args = []): ActivityLogEntry
    {
        $this->travelTo($when);
        $entry = $this->audit()->record($actor, $action, ...$args);
        $this->travelBack();

        return $entry;
    }
}
