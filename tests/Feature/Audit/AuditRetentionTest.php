<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\ActivityLogEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

class AuditRetentionTest extends AuditTestCase
{
    public function test_the_default_retention_is_two_years(): void
    {
        $this->assertSame(730, config('activitylog.clean_after_days'));
    }

    public function test_entries_older_than_the_retention_are_removed_and_newer_ones_kept(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $admin = $this->admin();
        $old = $this->entryAt('2024-10-10 11:59:59', $admin, 'a.old');       // one second past 730 days
        $edge = $this->entryAt('2024-10-10 12:00:00', $admin, 'a.edge');     // exactly 730 days: kept
        $recent = $this->entryAt('2026-10-09 12:00:00', $admin, 'a.recent');
        $this->travelTo('2026-10-10 12:00:00');

        $this->assertSame(0, Artisan::call('activitylog:clean', ['--force' => true]));

        $this->assertNull(ActivityLogEntry::find($old->id));
        $this->assertNotNull(ActivityLogEntry::find($edge->id), 'An entry exactly at the limit is kept.');
        $this->assertNotNull(ActivityLogEntry::find($recent->id));
        $this->assertStringContainsString('Deleted 1 record', Artisan::output());
    }

    public function test_the_retention_is_configurable(): void
    {
        config(['activitylog.clean_after_days' => 30]);
        $this->travelTo('2026-10-10 12:00:00');
        $admin = $this->admin();
        $old = $this->entryAt('2026-09-01 12:00:00', $admin);
        $recent = $this->entryAt('2026-09-20 12:00:00', $admin);
        $this->travelTo('2026-10-10 12:00:00');

        Artisan::call('activitylog:clean', ['--force' => true]);

        $this->assertNull(ActivityLogEntry::find($old->id));
        $this->assertNotNull(ActivityLogEntry::find($recent->id));
    }

    public function test_the_clean_command_cannot_shorten_the_configured_retention(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $admin = $this->admin();
        $yesterday = $this->entryAt('2026-10-09 12:00:00', $admin);
        $lastYear = $this->entryAt('2025-10-10 12:00:00', $admin);
        $this->travelTo('2026-10-10 12:00:00');

        Artisan::call('activitylog:clean', ['--force' => true, '--days' => 1]);

        $this->assertNotNull(ActivityLogEntry::find($yesterday->id));
        $this->assertNotNull(ActivityLogEntry::find($lastYear->id), 'Wiping the log with --days=1 is not possible.');
    }

    public function test_a_longer_period_than_the_retention_is_respected(): void
    {
        config(['activitylog.clean_after_days' => 30]);
        $this->travelTo('2026-10-10 12:00:00');
        $entry = $this->entryAt('2026-08-01 12:00:00', $this->admin());
        $this->travelTo('2026-10-10 12:00:00');

        Artisan::call('activitylog:clean', ['--force' => true, '--days' => 365]);

        $this->assertNotNull(ActivityLogEntry::find($entry->id));
    }

    public function test_the_job_is_scheduled_daily_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'activitylog:clean'));

        $this->assertNotNull($event, 'activitylog:clean must be scheduled.');
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('--force', $event->command);
    }
}
