<?php

namespace Tests\Feature\Audit;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverDocument;
use Illuminate\Testing\TestResponse;

class ActivityLogExportTest extends AuditTestCase
{
    /**
     * @return list<list<string>>
     */
    private function rows(TestResponse $response): array
    {
        $lines = array_filter(explode("\n", trim($response->streamedContent())));

        return array_map('str_getcsv', array_values($lines));
    }

    private function export(string $query = '', ?AdminRole $role = AdminRole::SuperAdmin): TestResponse
    {
        return $this->actingAs($this->admin($role ?? AdminRole::SuperAdmin))->get('/admin/activity-logs/export'.$query);
    }

    public function test_it_downloads_a_csv_with_a_header_and_one_row_per_entry(): void
    {
        $actor = $this->admin(AdminRole::Finance, ['name' => 'Morgan Webb']);
        $document = DriverDocument::factory()->create();
        $this->travelTo('2026-10-05 14:20:00');
        $this->audit()->record($actor, 'driver.document.rejected', $document, 'Expired policy, "renew" it', ['status' => 'pending'], ['status' => 'rejected']);
        $this->travelBack();

        $response = $this->export()->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename=activity-log-\d{8}-\d{6}\.csv$/', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $rows = $this->rows($response);
        $this->assertSame(['time_utc', 'actor', 'actor_role', 'action', 'target_type', 'target', 'reason', 'ip', 'user_agent', 'before', 'after'], $rows[0]);
        $this->assertCount(3, $rows, 'Header, this export\'s own audit entry (newest first), and the entry.');
        $this->assertSame('activity_log.exported', $rows[1][3]);
        $this->assertSame('2026-10-05 14:20:00', $rows[2][0]);
        $this->assertSame(['Morgan Webb', 'finance', 'driver.document.rejected', 'driver_document', sprintf('D-%04d / Driver\'s license', $document->driver_id), 'Expired policy, "renew" it'], array_slice($rows[2], 1, 6));
        $this->assertSame('{"status":"pending"}', $rows[2][9]);
        $this->assertSame('{"status":"rejected"}', $rows[2][10]);
    }

    public function test_it_exports_the_current_filter_across_all_pages(): void
    {
        $a = $this->admin(AdminRole::Admin);
        $b = $this->admin(AdminRole::Support);
        foreach (range(1, 14) as $i) {
            $this->audit()->record($b, 'driver.document.approved');
        }
        $this->audit()->record($a, 'driver.document.rejected');

        $byB = $this->rows($this->export("?filter[actor]={$b->id}"));
        $this->assertCount(15, $byB, 'All 14 rows although the page size is 10.');
        $this->assertSame(['driver.document.approved'], array_values(array_unique(array_column(array_slice($byB, 1), 3))));

        $this->assertCount(2, $this->rows($this->export('?filter[action]=driver.document.rejected')));
        $this->assertCount(1, $this->rows($this->export('?filter[search]=no-such-thing')), 'Only the header.');
    }

    public function test_it_honours_the_date_range_and_sort(): void
    {
        $admin = $this->admin();
        $this->entryAt('2026-10-01 10:00:00', $admin, 'a.one');
        $this->entryAt('2026-10-02 10:00:00', $admin, 'a.two');
        $this->entryAt('2026-10-03 10:00:00', $admin, 'a.three');

        $rows = $this->rows($this->export('?filter[from]=2026-10-02&filter[to]=2026-10-03&sort=occurred_at'));

        $this->assertSame(['a.two', 'a.three'], array_column(array_slice($rows, 1), 3));
        $this->export('?filter[from]=nonsense')->assertStatus(400);
        $this->export('?filter[bogus]=1')->assertStatus(400);
    }

    public function test_spreadsheet_formulas_in_cells_are_neutralised(): void
    {
        $admin = $this->admin(AdminRole::Admin, ['name' => '=HYPERLINK("http://evil","x")']);
        $this->audit()->record($admin, 'driver.document.rejected', null, "+cmd|' /C calc'!A0", targetLabel: '@SUM(1+1)');
        $this->audit()->record($admin, 'driver.document.rejected', null, '-2+3');
        $this->audit()->record($admin, 'driver.document.rejected', null, "\tTAB");

        $text = $this->export()->streamedContent();

        foreach (['=HYPERLINK', '+cmd', '@SUM', '-2+3', "\tTAB"] as $dangerous) {
            $this->assertStringNotContainsString('"'.$dangerous, $text);
            $this->assertStringNotContainsString(','.$dangerous, $text);
        }
        $this->assertStringContainsString("'=HYPERLINK", $text);
        $this->assertStringContainsString("'+cmd", $text);
        $this->assertStringContainsString("'@SUM", $text);
        $this->assertStringContainsString("'-2+3", $text);
    }

    public function test_sensitive_values_are_not_in_the_csv(): void
    {
        $this->audit()->record($this->admin(), 'driver.profile.updated', null, null, ['license_number' => 'DL-OLD-1'], ['license_number' => 'DL-NEW-2']);

        $text = $this->export()->streamedContent();

        $this->assertStringNotContainsString('DL-OLD-1', $text);
        $this->assertStringNotContainsString('DL-NEW-2', $text);
        $this->assertStringContainsString('license_number', $text);
    }

    public function test_the_export_is_capped(): void
    {
        config(['taxikosmos.audit.export_max_rows' => 3]);
        $admin = $this->admin();
        foreach (range(1, 8) as $i) {
            $this->audit()->record($admin, 'driver.document.approved');
        }

        $this->assertCount(4, $this->rows($this->export()), 'Header plus the cap.');
    }

    public function test_exporting_is_itself_audited_with_the_filters_used(): void
    {
        $admin = $this->admin(AdminRole::Admin, ['name' => 'Jordan Avery']);
        $this->audit()->record($admin, 'driver.document.approved');

        $this->actingAs($admin)->get('/admin/activity-logs/export?filter[action]=driver&filter[from]=2026-10-01')->assertOk()->streamedContent();

        $entry = ActivityLogEntry::where('description', 'activity_log.exported')->sole();
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame(['filters' => ['action' => 'driver', 'from' => '2026-10-01'], 'max_rows' => 50000], $entry->properties['context']);
    }

    public function test_a_refused_export_is_not_logged_as_an_export(): void
    {
        $this->export('?filter[bogus]=1')->assertStatus(400);
        $this->export('', AdminRole::Finance)->assertForbidden();

        $this->assertSame(0, ActivityLogEntry::where('description', 'activity_log.exported')->count());
    }

    public function test_it_needs_activity_log_view_and_a_session(): void
    {
        foreach ([AdminRole::Finance, AdminRole::Support, AdminRole::Dispatcher] as $role) {
            $this->export('', $role)->assertForbidden();
        }
        $this->export('', AdminRole::Admin)->assertOk();
        auth()->logout();
        $this->get('/admin/activity-logs/export')->assertRedirect('/login');
    }
}
