<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class DriverDocumentsTabTest extends DriverDocumentTestCase
{
    public function test_a_real_driver_shows_their_documents_status_and_review_endpoints(): void
    {
        $reviewer = User::factory()->create(['name' => 'Sun Li']);
        $driver = DriverProfile::factory()->create(['verification_status' => DriverVerificationStatus::Rejected]);
        $approved = $this->pendingDocument($driver, DriverDocumentType::License, ['status' => DriverDocumentStatus::Approved, 'reviewed_by' => $reviewer->id, 'expires_at' => '2028-04-30']);
        $rejected = $this->pendingDocument($driver, DriverDocumentType::IdCard, ['status' => DriverDocumentStatus::Rejected, 'rejection_reason' => 'Blurry', 'mime_type' => 'image/png']);
        $pending = $this->pendingDocument($driver, DriverDocumentType::Insurance);
        $this->pendingDocument(); // another driver's document

        $this->actingAs($this->admin())->get("/admin/drivers/{$driver->id}?tab=documents")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Drivers/Show')
                ->where('driver.verification_status', 'rejected')
                ->has('driver.documents', 3)
                ->where('driver.documents.0.id', $approved->id)
                ->where('driver.documents.0.type', 'license')
                ->where('driver.documents.0.status', 'approved')
                ->where('driver.documents.0.expires_at', '2028-04-30')
                ->where('driver.documents.0.reviewer', 'Sun Li')
                ->where('driver.documents.1.id', $rejected->id)
                ->where('driver.documents.1.rejection_reason', 'Blurry')
                ->where('driver.documents.1.mime_type', 'image/png')
                ->where('driver.documents.2.id', $pending->id)
                ->where('driver.documents.2.status', 'pending')
                ->where('actions.approveDocument', "/admin/drivers/{$driver->id}/documents/{id}/approve")
                ->where('actions.rejectDocument', "/admin/drivers/{$driver->id}/documents/{id}/reject"));
    }

    public function test_the_page_issues_one_query_set_for_any_number_of_documents(): void
    {
        $driver = DriverProfile::factory()->create();
        $reviewer = User::factory()->create();
        foreach (range(1, 6) as $i) {
            $this->pendingDocument($driver, DriverDocumentType::License, ['status' => DriverDocumentStatus::Approved, 'reviewed_by' => $reviewer->id]);
        }
        $admin = $this->admin();
        $this->actingAs($admin)->get("/admin/drivers/{$driver->id}?tab=documents")->assertOk(); // warms caches and the session
        $this->actingAs($admin)->get("/admin/drivers/{$driver->id}?tab=documents")->assertOk();

        DB::enableQueryLog();
        $this->actingAs($admin)->get("/admin/drivers/{$driver->id}?tab=documents")->assertOk();
        $withSix = count(DB::getQueryLog());

        $this->pendingDocument($driver, DriverDocumentType::IdCard, ['status' => DriverDocumentStatus::Approved, 'reviewed_by' => $reviewer->id]);
        DB::flushQueryLog();
        $this->actingAs($admin)->get("/admin/drivers/{$driver->id}?tab=documents")->assertOk();

        $this->assertSame($withSix, count(DB::getQueryLog()), 'Reviewer names are eager loaded, not queried per document.');
    }

    public function test_a_driver_without_documents_shows_an_empty_list(): void
    {
        $driver = DriverProfile::factory()->create();

        $this->actingAs($this->admin())->get("/admin/drivers/{$driver->id}?tab=documents")
            ->assertInertia(fn (Assert $page) => $page->where('driver.documents', [])->where('driver.verification_status', 'pending'));
    }

    public function test_a_fixture_driver_keeps_fixture_documents_and_has_no_review_endpoints(): void
    {
        $this->actingAs($this->admin())->get('/admin/drivers/8?tab=documents')
            ->assertInertia(fn (Assert $page) => $page
                ->has('driver.documents', 4)
                ->missing('driver.documents.0.file_url')
                ->where('actions.approveDocument', null)
                ->where('actions.rejectDocument', null));
    }

    public function test_an_unknown_driver_is_still_a_404(): void
    {
        $this->actingAs($this->admin())->get('/admin/drivers/987654')->assertNotFound();
    }
}
