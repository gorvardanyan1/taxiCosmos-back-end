<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Events\Realtime\DriverVerificationChanged;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\DriverDocuments\DriverDocumentReviewer;
use App\Services\DriverDocuments\DriverVerificationSync;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class DriverVerificationStatusTest extends DriverDocumentTestCase
{
    private User $reviewer;

    private function review(DriverDocument $document, bool $approve = true): void
    {
        $reviewer = app(DriverDocumentReviewer::class);
        $approve ? $reviewer->approve($document, $this->reviewer) : $reviewer->reject($document, $this->reviewer, 'Not good');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewer = $this->admin();
    }

    public function test_approving_every_required_document_verifies_the_driver(): void
    {
        $this->travelTo('2026-10-10 08:00:00');
        $driver = DriverProfile::factory()->create();
        $license = $this->pendingDocument($driver, DriverDocumentType::License);
        $idCard = $this->pendingDocument($driver, DriverDocumentType::IdCard);

        $this->review($license);
        $this->assertSame(DriverVerificationStatus::Pending, $driver->fresh()->verification_status, 'One of two required documents is not enough.');
        $this->assertNull($driver->fresh()->approved_at);

        $this->review($idCard);

        $fresh = $driver->fresh();
        $this->assertSame(DriverVerificationStatus::Approved, $fresh->verification_status);
        $this->assertSame($this->reviewer->id, $fresh->approved_by);
        $this->assertSame('2026-10-10 08:00:00', $fresh->approved_at->toDateTimeString());
    }

    public function test_the_status_follows_the_http_approve_endpoint(): void
    {
        $driver = DriverProfile::factory()->create();
        $documents = [$this->pendingDocument($driver, DriverDocumentType::License), $this->pendingDocument($driver, DriverDocumentType::IdCard)];

        foreach ($documents as $document) {
            $this->actingAs($this->reviewer)->post($this->approveUrl($document))->assertRedirect();
        }

        $this->assertSame(DriverVerificationStatus::Approved, $driver->fresh()->verification_status);
    }

    public function test_optional_documents_do_not_hold_back_verification(): void
    {
        $driver = DriverProfile::factory()->create();
        $this->pendingDocument($driver, DriverDocumentType::BackgroundCheck);
        $this->review($this->pendingDocument($driver, DriverDocumentType::License));
        $this->review($this->pendingDocument($driver, DriverDocumentType::IdCard));

        $this->assertSame(DriverVerificationStatus::Approved, $driver->fresh()->verification_status);
    }

    public function test_approving_only_optional_documents_never_verifies_a_driver(): void
    {
        $driver = DriverProfile::factory()->create();

        $this->review($this->pendingDocument($driver, DriverDocumentType::BackgroundCheck));

        $this->assertSame(DriverVerificationStatus::Pending, $driver->fresh()->verification_status);
    }

    public function test_which_types_are_required_is_configurable(): void
    {
        config(['taxikosmos.documents.required' => ['license', 'background_check']]);
        $driver = DriverProfile::factory()->create();

        $this->review($this->pendingDocument($driver, DriverDocumentType::License));
        $this->assertSame(DriverVerificationStatus::Pending, $driver->fresh()->verification_status);

        $this->review($this->pendingDocument($driver, DriverDocumentType::BackgroundCheck));
        $this->assertSame(DriverVerificationStatus::Approved, $driver->fresh()->verification_status);

        config(['taxikosmos.documents.required' => ['license']]);
        $single = DriverProfile::factory()->create();
        $this->review($this->pendingDocument($single, DriverDocumentType::License));
        $this->assertSame(DriverVerificationStatus::Approved, $single->fresh()->verification_status);
    }

    public function test_a_misconfigured_required_list_fails_loudly(): void
    {
        $document = $this->pendingDocument();

        foreach ([[], ['passport']] as $required) {
            config(['taxikosmos.documents.required' => $required]);
            try {
                $this->review($document);
                $this->fail('Expected the misconfiguration to be reported.');
            } catch (RuntimeException) {
                $this->assertSame(DriverVerificationStatus::Pending, $document->driver->fresh()->verification_status);
                $this->assertSame('pending', $document->fresh()->status->value, 'The review rolls back with the failed status update.');
            }
        }
    }

    public function test_rejecting_a_required_document_marks_the_driver_rejected_and_a_new_upload_makes_it_pending_again(): void
    {
        $driver = DriverProfile::factory()->create();
        $license = $this->pendingDocument($driver, DriverDocumentType::License);
        $this->pendingDocument($driver, DriverDocumentType::IdCard);

        $this->review($license, approve: false);
        $this->assertSame(DriverVerificationStatus::Rejected, $driver->fresh()->verification_status);

        $this->pendingDocument($driver, DriverDocumentType::License);
        app(DriverVerificationSync::class)->sync($driver, null);
        $this->assertSame(DriverVerificationStatus::Pending, $driver->fresh()->verification_status);
    }

    public function test_rejecting_a_document_of_a_verified_driver_while_another_valid_one_exists_keeps_them_verified(): void
    {
        $driver = DriverProfile::factory()->create();
        $this->review($this->pendingDocument($driver, DriverDocumentType::License));
        $this->review($this->pendingDocument($driver, DriverDocumentType::IdCard));
        $this->assertSame(DriverVerificationStatus::Approved, $driver->fresh()->verification_status);

        // A renewal upload that gets rejected must not undo the still-valid approved document.
        $renewal = $this->pendingDocument($driver, DriverDocumentType::License);
        $this->review($renewal, approve: false);

        $this->assertSame(DriverVerificationStatus::Approved, $driver->fresh()->verification_status);
    }

    public function test_an_approved_document_past_its_expiry_no_longer_counts(): void
    {
        $this->travelTo('2026-10-10 09:00:00');
        $driver = DriverProfile::factory()->create();
        $this->review($this->pendingDocument($driver, DriverDocumentType::License, ['expires_at' => '2026-10-10']));
        $this->review($this->pendingDocument($driver, DriverDocumentType::IdCard));
        $this->assertSame(DriverVerificationStatus::Approved, $driver->fresh()->verification_status);

        $this->travelTo('2026-10-11 09:00:00');
        $this->pendingDocument($driver, DriverDocumentType::BackgroundCheck);
        $sync = app(DriverVerificationSync::class);
        $sync->sync($driver, null);

        $this->assertSame(DriverVerificationStatus::Expired, $driver->fresh()->verification_status);
        $this->assertNull($driver->fresh()->approved_at);
        $this->assertNull($driver->fresh()->approved_by);
    }

    public function test_a_driver_who_loses_approval_keeps_no_approver_even_when_another_admin_triggers_it(): void
    {
        $this->travelTo('2026-10-10 09:00:00');
        $driver = DriverProfile::factory()->create();
        $this->review($this->pendingDocument($driver, DriverDocumentType::License, ['expires_at' => '2026-10-10']));
        $this->review($this->pendingDocument($driver, DriverDocumentType::IdCard));
        $this->assertSame($this->reviewer->id, $driver->fresh()->approved_by);

        // The license lapses; a different admin then reviews an unrelated optional document.
        $this->travelTo('2026-10-11 09:00:00');
        $other = $this->admin();
        app(DriverDocumentReviewer::class)->approve($this->pendingDocument($driver, DriverDocumentType::BackgroundCheck), $other);

        $fresh = $driver->fresh();
        $this->assertSame(DriverVerificationStatus::Expired, $fresh->verification_status);
        $this->assertNull($fresh->approved_by, 'Nobody approved the driver in this state.');
        $this->assertNull($fresh->approved_at);
    }

    public function test_rejected_beats_expired_beats_pending(): void
    {
        $this->travelTo('2026-10-10 09:00:00');
        $driver = DriverProfile::factory()->create();
        $this->pendingDocument($driver, DriverDocumentType::License, ['status' => 'expired', 'expires_at' => '2026-09-01']);
        $this->pendingDocument($driver, DriverDocumentType::IdCard);
        $sync = app(DriverVerificationSync::class);

        $sync->sync($driver, null);
        $this->assertSame(DriverVerificationStatus::Expired, $driver->fresh()->verification_status);

        $this->review($driver->documents()->where('type', 'id_card')->sole(), approve: false);
        $this->assertSame(DriverVerificationStatus::Rejected, $driver->fresh()->verification_status);
    }

    public function test_a_verification_change_is_logged_and_published_exactly_once_per_change(): void
    {
        Event::fake([DriverVerificationChanged::class]);
        $driver = DriverProfile::factory()->create();
        $license = $this->pendingDocument($driver, DriverDocumentType::License);
        $idCard = $this->pendingDocument($driver, DriverDocumentType::IdCard);

        $this->review($license);
        Event::assertNotDispatched(DriverVerificationChanged::class);
        $this->assertSame(0, Activity::where('event', 'verification_changed')->count());

        $this->review($idCard);

        Event::assertDispatchedTimes(DriverVerificationChanged::class, 1);
        $log = Activity::where('event', 'verification_changed')->sole();
        $this->assertSame($this->reviewer->id, $log->causer_id);
        $this->assertSame($driver->id, $log->subject_id);
        $this->assertSame(['verification_status' => 'pending'], $log->properties['old']);
        $this->assertSame(['verification_status' => 'approved'], $log->properties['new']);
    }

    public function test_other_drivers_are_untouched(): void
    {
        $driver = DriverProfile::factory()->create();
        $other = DriverProfile::factory()->create();
        $this->pendingDocument($other, DriverDocumentType::License);
        $this->review($this->pendingDocument($driver, DriverDocumentType::License));
        $this->review($this->pendingDocument($driver, DriverDocumentType::IdCard));

        $this->assertSame(DriverVerificationStatus::Pending, $other->fresh()->verification_status);
        $this->assertNull($other->fresh()->approved_by);
    }
}
