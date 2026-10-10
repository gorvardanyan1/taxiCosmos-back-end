<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DriverDocumentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function insert(array $attributes): void
    {
        $row = [
            'driver_id' => DriverProfile::factory()->create()->id,
            'type' => 'license', 'file_path' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ...$attributes,
        ];

        // A savepoint, so a refused row does not abort the test's transaction.
        DB::transaction(fn () => DB::table('driver_documents')->insert($row));
    }

    public function test_enum_values_match_the_task_definition(): void
    {
        $this->assertSame(['license', 'id_card', 'vehicle_registration', 'insurance', 'background_check'], DriverDocumentType::values());
        $this->assertSame(['pending', 'approved', 'rejected', 'expired'], array_column(DriverDocumentStatus::cases(), 'value'));
    }

    public function test_a_new_document_defaults_to_pending_and_unreviewed(): void
    {
        $document = DriverDocument::factory()->create()->fresh();

        $this->assertSame(DriverDocumentStatus::Pending, $document->status);
        $this->assertNull($document->reviewed_by);
        $this->assertNull($document->reviewed_at);
        $this->assertNull($document->rejection_reason);
        $this->assertNull($document->document_number);
        $this->assertNull($document->expires_at);
    }

    public function test_status_and_review_fields_cannot_be_mass_assigned(): void
    {
        $document = new DriverDocument(['type' => 'license', 'status' => 'approved', 'reviewed_by' => 1, 'rejection_reason' => 'x', 'driver_id' => 99]);

        $this->assertSame(DriverDocumentStatus::Pending, $document->status);
        $this->assertNull($document->reviewed_by);
        $this->assertNull($document->driver_id);
    }

    public function test_the_database_rejects_unknown_types_and_statuses(): void
    {
        foreach ([['type' => 'passport'], ['status' => 'verified']] as $bad) {
            try {
                $this->insert($bad);
                $this->fail('Expected a check violation.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_only_vehicle_papers_can_name_a_vehicle(): void
    {
        $driver = DriverProfile::factory()->create();
        $vehicle = Vehicle::factory()->for($driver, 'driver')->create();

        $this->insert(['driver_id' => $driver->id, 'type' => 'insurance', 'vehicle_id' => $vehicle->id]);
        $this->insert(['driver_id' => $driver->id, 'type' => 'vehicle_registration', 'vehicle_id' => $vehicle->id]);
        $this->insert(['driver_id' => $driver->id, 'type' => 'insurance', 'vehicle_id' => null]);

        foreach (['license', 'id_card', 'background_check'] as $type) {
            try {
                $this->insert(['driver_id' => $driver->id, 'type' => $type, 'vehicle_id' => $vehicle->id]);
                $this->fail("A {$type} must not name a vehicle.");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_a_rejection_must_carry_a_reason(): void
    {
        foreach ([null, ''] as $reason) {
            try {
                $this->insert(['status' => 'rejected', 'rejection_reason' => $reason]);
                $this->fail('A rejection without a reason must be refused.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }

        $this->insert(['status' => 'rejected', 'rejection_reason' => 'Blurry']);
        $this->assertSame(1, DriverDocument::count());
    }

    public function test_a_driver_with_documents_cannot_be_deleted(): void
    {
        $document = DriverDocument::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('driver_profiles')->where('id', $document->driver_id)->delete();
    }

    public function test_deleting_a_reviewer_keeps_the_document_and_clears_the_reference(): void
    {
        $reviewer = User::factory()->create();
        $document = DriverDocument::factory()->approved($reviewer)->create();

        DB::table('users')->where('id', $reviewer->id)->delete();

        $this->assertNull($document->fresh()->reviewed_by);
        $this->assertSame(DriverDocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_the_relationships_resolve(): void
    {
        $reviewer = User::factory()->create();
        $document = DriverDocument::factory()->approved($reviewer)->create();

        $this->assertTrue($document->driver->is($document->driver()->first()));
        $this->assertSame($document->id, $document->driver->documents->sole()->id);
        $this->assertSame($reviewer->id, $document->reviewer->id);
    }
}
