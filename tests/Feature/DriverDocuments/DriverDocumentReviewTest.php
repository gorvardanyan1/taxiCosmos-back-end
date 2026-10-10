<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\AdminRole;
use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Models\DriverProfile;
use Spatie\Activitylog\Models\Activity;

class DriverDocumentReviewTest extends DriverDocumentTestCase
{
    public function test_approving_a_pending_document_records_the_reviewer_and_time(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $admin = $this->admin(AdminRole::Support);
        $document = $this->pendingDocument();

        $this->actingAs($admin)->from('/admin/drivers/'.$document->driver_id)->post($this->approveUrl($document))
            ->assertRedirect('/admin/drivers/'.$document->driver_id)
            ->assertSessionHas('success', 'Document approved.');

        $fresh = $document->fresh();
        $this->assertSame(DriverDocumentStatus::Approved, $fresh->status);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertSame('2026-10-10 12:00:00', $fresh->reviewed_at->toDateTimeString());
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_rejecting_stores_the_reason_and_reviewer(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $document = $this->pendingDocument();

        $this->actingAs($admin)->post($this->rejectUrl($document), ['reason' => 'Photo is blurry'])->assertRedirect();

        $fresh = $document->fresh();
        $this->assertSame(DriverDocumentStatus::Rejected, $fresh->status);
        $this->assertSame('Photo is blurry', $fresh->rejection_reason);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_a_rejection_without_a_reason_is_refused_and_nothing_changes(): void
    {
        $admin = $this->admin();
        $document = $this->pendingDocument();

        foreach ([[], ['reason' => ''], ['reason' => '    '], ['reason' => str_repeat('x', 1001)], ['reason' => ['a']]] as $payload) {
            $this->actingAs($admin)->post($this->rejectUrl($document), $payload)->assertSessionHasErrors('reason');
        }

        $fresh = $document->fresh();
        $this->assertSame(DriverDocumentStatus::Pending, $fresh->status);
        $this->assertNull($fresh->reviewed_by);
        $this->assertSame(0, Activity::count());
    }

    public function test_a_rejection_reason_of_exactly_the_maximum_length_is_accepted(): void
    {
        $document = $this->pendingDocument();

        $this->actingAs($this->admin())->post($this->rejectUrl($document), ['reason' => str_repeat('x', 1000)])->assertSessionHasNoErrors();

        $this->assertSame(1000, mb_strlen($document->fresh()->rejection_reason));
    }

    public function test_only_roles_with_drivers_verify_can_review(): void
    {
        $document = $this->pendingDocument();

        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $admin = $this->admin($role);
            $this->actingAs($admin)->post($this->approveUrl($document))->assertForbidden();
            $this->actingAs($admin)->post($this->rejectUrl($document), ['reason' => 'No'])->assertForbidden();
        }

        $this->assertSame(DriverDocumentStatus::Pending, $document->fresh()->status);

        foreach ([AdminRole::Support, AdminRole::Admin, AdminRole::SuperAdmin] as $role) {
            $this->actingAs($this->admin($role))->post($this->approveUrl($this->pendingDocument()))->assertRedirect();
        }
    }

    public function test_guests_are_sent_to_login_and_nothing_changes(): void
    {
        $document = $this->pendingDocument();

        $this->post($this->approveUrl($document))->assertRedirect('/login');
        $this->post($this->rejectUrl($document), ['reason' => 'No'])->assertRedirect('/login');

        $this->assertSame(DriverDocumentStatus::Pending, $document->fresh()->status);
    }

    public function test_a_document_cannot_be_reviewed_through_another_drivers_url(): void
    {
        $admin = $this->admin();
        $document = $this->pendingDocument();
        $other = DriverProfile::factory()->create();

        $this->actingAs($admin)->post("/admin/drivers/{$other->id}/documents/{$document->id}/approve")->assertNotFound();
        $this->actingAs($admin)->post("/admin/drivers/{$other->id}/documents/{$document->id}/reject", ['reason' => 'No'])->assertNotFound();
        $this->actingAs($admin)->post("/admin/drivers/{$document->driver_id}/documents/999999/approve")->assertNotFound();

        $this->assertSame(DriverDocumentStatus::Pending, $document->fresh()->status);
    }

    public function test_a_reviewed_document_cannot_be_reviewed_again(): void
    {
        $admin = $this->admin();
        $approved = $this->pendingDocument(attributes: ['status' => DriverDocumentStatus::Approved, 'reviewed_by' => $admin->id]);
        $rejected = $this->pendingDocument(type: DriverDocumentType::IdCard, attributes: ['status' => DriverDocumentStatus::Rejected, 'rejection_reason' => 'Blurry']);
        $expired = $this->pendingDocument(type: DriverDocumentType::Insurance, attributes: ['status' => DriverDocumentStatus::Expired]);

        foreach ([$approved, $rejected, $expired] as $document) {
            $this->actingAs($admin)->postJson($this->approveUrl($document))
                ->assertStatus(409)->assertJson(['message' => 'This document has already been reviewed.']);
            $this->actingAs($admin)->postJson($this->rejectUrl($document), ['reason' => 'Changed my mind'])->assertStatus(409);
        }

        $this->assertSame(DriverDocumentStatus::Approved, $approved->fresh()->status);
        $this->assertSame(DriverDocumentStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame('Blurry', $rejected->fresh()->rejection_reason);
        $this->assertSame(0, Activity::count(), 'A refused review is not logged as a review.');
    }

    public function test_approving_twice_has_exactly_one_effect(): void
    {
        $admin = $this->admin();
        $document = $this->pendingDocument();

        $this->actingAs($admin)->post($this->approveUrl($document))->assertRedirect();
        $reviewedAt = $document->fresh()->reviewed_at;
        $this->travel(5)->minutes();
        $this->actingAs($admin)->postJson($this->approveUrl($document))->assertStatus(409);

        $this->assertEquals($reviewedAt, $document->fresh()->reviewed_at);
        $this->assertSame(1, Activity::where('event', 'approved')->count());
    }

    public function test_the_admin_ui_gets_a_conflict_as_a_flash_message_on_the_same_page(): void
    {
        $admin = $this->admin();
        $document = $this->pendingDocument(attributes: ['status' => DriverDocumentStatus::Approved]);

        $this->actingAs($admin)->from('/admin/drivers/1')->withHeaders(['X-Inertia' => 'true'])->post($this->approveUrl($document))
            ->assertRedirect('/admin/drivers/1')
            ->assertSessionHas('error', 'This document has already been reviewed.');
    }

    public function test_an_expired_document_cannot_be_approved_but_can_be_rejected(): void
    {
        $this->travelTo('2026-10-10 09:00:00');
        $admin = $this->admin();
        $expired = $this->pendingDocument(attributes: ['expires_at' => '2026-10-09']);

        $this->actingAs($admin)->postJson($this->approveUrl($expired))
            ->assertStatus(409)->assertJsonPath('message', 'This document has expired and cannot be approved. The driver must upload a new one.');
        $this->assertSame(DriverDocumentStatus::Pending, $expired->fresh()->status);

        $this->actingAs($admin)->post($this->rejectUrl($expired), ['reason' => 'Expired'])->assertRedirect();
        $this->assertSame(DriverDocumentStatus::Rejected, $expired->fresh()->status);
    }

    public function test_a_document_expiring_today_can_still_be_approved(): void
    {
        $this->travelTo('2026-10-10 23:30:00');
        $document = $this->pendingDocument(attributes: ['expires_at' => '2026-10-10']);

        $this->actingAs($this->admin())->post($this->approveUrl($document))->assertRedirect();

        $this->assertSame(DriverDocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_every_review_is_activity_logged_with_actor_target_and_reason(): void
    {
        $admin = $this->admin(AdminRole::Support);
        $approved = $this->pendingDocument();
        $rejected = $this->pendingDocument(type: DriverDocumentType::IdCard);

        $this->actingAs($admin)->post($this->approveUrl($approved));
        $this->actingAs($admin)->post($this->rejectUrl($rejected), ['reason' => 'Name does not match']);

        $approval = Activity::where('event', 'approved')->sole();
        $this->assertSame($admin->id, $approval->causer_id);
        $this->assertSame($approved->id, $approval->subject_id);
        $this->assertSame($approved::class, $approval->subject_type);
        $this->assertSame('driver-documents', $approval->log_name);
        $this->assertSame(['status' => 'pending'], $approval->properties['old']);
        $this->assertSame(['status' => 'approved'], $approval->properties['new']);
        $this->assertSame($approved->driver_id, $approval->properties['driver_id']);
        $this->assertSame('license', $approval->properties['type']);

        $rejection = Activity::where('event', 'rejected')->sole();
        $this->assertSame($admin->id, $rejection->causer_id);
        $this->assertSame($rejected->id, $rejection->subject_id);
        $this->assertSame('Name does not match', $rejection->properties['reason']);
        $this->assertSame(['status' => 'rejected'], $rejection->properties['new']);
    }

    public function test_the_document_number_never_reaches_the_activity_log(): void
    {
        $document = $this->pendingDocument(attributes: ['document_number' => 'AB-1234567']);

        $this->actingAs($this->admin())->post($this->approveUrl($document));

        $this->assertStringNotContainsString('1234567', Activity::all()->toJson());
    }
}
