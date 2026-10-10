<?php

namespace Tests\Feature\Audit;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use InvalidArgumentException;

class AuditLoggerTest extends AuditTestCase
{
    public function test_an_entry_keeps_its_own_copy_of_who_what_where_and_why(): void
    {
        $admin = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $document = DriverDocument::factory()->create();

        $entry = $this->withRequest('10.10.4.9', 'Mozilla/5.0 QA', fn () => $this->audit()->record(
            $admin, 'driver.document.rejected', $document, 'Expired policy', ['status' => 'pending'], ['status' => 'rejected'], ['driver_id' => $document->driver_id],
        ));

        $fresh = $entry->fresh();
        $this->assertSame('admin', $fresh->log_name);
        $this->assertSame('driver.document.rejected', $fresh->description);
        $this->assertSame('rejected', $fresh->event);
        $this->assertSame($admin->id, $fresh->causer_id);
        $this->assertSame($document->id, $fresh->subject_id);
        $this->assertSame(DriverDocument::class, $fresh->subject_type);
        $this->assertSame('Expired policy', $fresh->properties['reason']);
        $this->assertSame('10.10.4.9', $fresh->properties['ip']);
        $this->assertSame('Mozilla/5.0 QA', $fresh->properties['user_agent']);
        $this->assertSame('Riley Chen', $fresh->properties['actor_name']);
        $this->assertSame('support', $fresh->properties['actor_role']);
        $this->assertSame(sprintf('D-%04d / Driver\'s license', $document->driver_id), $fresh->properties['target_label']);
        $this->assertSame(['driver_id' => $document->driver_id], $fresh->properties['context']);
        $this->assertSame(['status' => 'pending'], $fresh->attribute_changes['old']);
        $this->assertSame(['status' => 'rejected'], $fresh->attribute_changes['attributes']);
    }

    public function test_the_entry_stays_readable_after_the_actor_is_renamed_and_the_record_deleted(): void
    {
        $admin = $this->admin(AdminRole::Admin, ['name' => 'Jordan Avery']);
        $profile = DriverProfile::factory()->create();
        $entry = $this->audit()->record($admin, 'driver.verification.changed', $profile, null, ['verification_status' => 'pending'], ['verification_status' => 'approved']);

        $admin->forceFill(['name' => 'Someone Else'])->save();
        $profile->delete();

        $fresh = $entry->fresh();
        $this->assertSame('Jordan Avery', $fresh->properties['actor_name']);
        $this->assertSame(sprintf('D-%04d', $profile->id), $fresh->properties['target_label']);
    }

    public function test_an_action_without_a_target_or_actor_is_a_system_entry(): void
    {
        $entry = $this->audit()->record(null, 'activity_log.exported');

        $this->assertNull($entry->causer_id);
        $this->assertNull($entry->subject_id);
        $this->assertArrayNotHasKey('actor_name', $entry->properties->all());
    }

    public function test_a_blank_reason_is_stored_as_no_reason(): void
    {
        $entry = $this->audit()->record($this->admin(), 'driver.document.approved', reason: '   ');

        $this->assertArrayNotHasKey('reason', $entry->properties->all());
    }

    public function test_a_required_reason_cannot_be_empty(): void
    {
        $admin = $this->admin();

        foreach ([null, '', '   '] as $reason) {
            try {
                $this->audit()->record($admin, 'payment.refund.created', reason: $reason, reasonRequired: true);
                $this->fail('An empty reason must be refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('payment.refund.created', $e->getMessage());
            }
        }

        $this->assertSame(0, ActivityLogEntry::count());
        $this->assertSame('Fare adjustment', $this->audit()->record($admin, 'payment.refund.created', reason: ' Fare adjustment ', reasonRequired: true)->properties['reason']);
    }

    public function test_sensitive_values_never_reach_the_stored_diff(): void
    {
        $entry = $this->audit()->record(
            $this->admin(), 'driver.profile.updated', null, null,
            ['license_number' => 'DL-OLD-1', 'status' => 'pending', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'],
            ['license_number' => 'DL-NEW-2', 'status' => 'approved', 'two_factor_secret' => null],
        );

        $stored = $entry->fresh()->toJson();
        $this->assertStringNotContainsString('DL-OLD-1', $stored);
        $this->assertStringNotContainsString('DL-NEW-2', $stored);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $stored);
        $this->assertSame(['license_number' => 'changed', 'status' => 'pending', 'two_factor_secret' => 'changed'], $entry->fresh()->attribute_changes['old']);
        $this->assertSame(['license_number' => 'changed', 'status' => 'approved', 'two_factor_secret' => 'changed'], $entry->fresh()->attribute_changes['attributes']);
    }

    public function test_a_very_long_user_agent_is_truncated(): void
    {
        $entry = $this->withRequest('10.0.0.1', str_repeat('A', 600), fn () => $this->audit()->record($this->admin(), 'driver.document.approved'));

        $this->assertSame(255, mb_strlen($entry->properties['user_agent']));
    }

    public function test_the_actor_role_is_the_admin_role_not_an_unrelated_one(): void
    {
        $admin = $this->admin(AdminRole::Finance);

        $this->assertSame('finance', $this->audit()->record($admin, 'payment.refund.created')->properties['actor_role']);
    }

    /**
     * Runs the callback with the given client IP and user agent on the current request.
     */
    private function withRequest(string $ip, string $agent, callable $callback): mixed
    {
        $request = request();
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('User-Agent', $agent);

        return $callback();
    }
}
