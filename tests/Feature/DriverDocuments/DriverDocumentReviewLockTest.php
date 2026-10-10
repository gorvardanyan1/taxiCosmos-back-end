<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\AdminRole;
use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Exceptions\DocumentReviewException;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\DriverDocuments\DriverDocumentReviewer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves a review takes the driver's row lock (so two reviewers of one driver queue instead of
 * both deciding on stale data) and re-checks the document state under the lock. Committed data
 * and a second real connection, like DriverRowLockTest.
 */
class DriverDocumentReviewLockTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_review_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::OTHER => config('database.connections.pgsql'), 'taxikosmos.documents.required' => ['license', 'id_card']]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::purge(self::OTHER);
        DB::statement('RESET lock_timeout');
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function holdLock(string $table, int $id): void
    {
        DB::connection(self::OTHER)->beginTransaction();
        DB::connection(self::OTHER)->table($table)->where('id', $id)->lockForUpdate()->first();
        DB::statement("SET lock_timeout = '300ms'");
    }

    private function assertBlocked(callable $review, DriverDocument $document): void
    {
        try {
            $review();
            $this->fail('The review should have waited for the lock.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected a lock timeout.');
        }

        $this->assertSame(DriverDocumentStatus::Pending, $document->fresh()->status, 'Nothing changes while the lock is held.');
        $this->assertSame(DriverVerificationStatus::Pending, $document->driver->fresh()->verification_status);
    }

    public function test_a_review_waits_for_the_document_row_lock(): void
    {
        $document = DriverDocument::factory()->for(DriverProfile::factory()->create(), 'driver')->create();
        $reviewer = User::factory()->admin(AdminRole::Support)->create();

        $this->holdLock('driver_documents', $document->id);

        $this->assertBlocked(fn () => app(DriverDocumentReviewer::class)->approve($document, $reviewer), $document);
        $this->assertBlocked(fn () => app(DriverDocumentReviewer::class)->reject($document, $reviewer, 'No'), $document);
    }

    public function test_a_review_waits_for_the_driver_row_lock_so_verification_is_decided_one_at_a_time(): void
    {
        config(['taxikosmos.documents.required' => ['license']]);
        $document = DriverDocument::factory()->for(DriverProfile::factory()->create(), 'driver')->create();
        $reviewer = User::factory()->admin(AdminRole::Support)->create();

        $this->holdLock('driver_profiles', $document->driver_id);

        $this->assertBlocked(fn () => app(DriverDocumentReviewer::class)->approve($document, $reviewer), $document);
    }

    public function test_the_state_check_reads_the_document_and_driver_under_a_row_lock(): void
    {
        $document = DriverDocument::factory()->for(DriverProfile::factory()->create(), 'driver')->create();
        $reviewer = User::factory()->admin(AdminRole::Support)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(DriverDocumentReviewer::class)->reject($document, $reviewer, 'No');

        $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_ends_with($sql, 'for update'))->implode("\n");
        // A blocked UPDATE alone would let a second reviewer decide on a stale read; the lock must
        // be taken by the read that decides.
        $this->assertStringContainsString('from "driver_documents"', $locking);
        $this->assertStringContainsString('from "driver_profiles"', $locking);
    }

    public function test_a_review_decided_by_someone_else_while_waiting_is_not_applied_twice(): void
    {
        $driver = DriverProfile::factory()->create();
        $document = DriverDocument::factory()->for($driver, 'driver')->ofType(DriverDocumentType::License)->create();
        $first = User::factory()->admin(AdminRole::Support)->create();
        $second = User::factory()->admin(AdminRole::Admin)->create();

        // Both admins loaded the same pending document (two open tabs).
        $staleForSecond = DriverDocument::find($document->id);
        app(DriverDocumentReviewer::class)->reject($document, $first, 'Blurry');

        try {
            app(DriverDocumentReviewer::class)->approve($staleForSecond, $second);
            $this->fail('The second reviewer works from a stale copy and must be refused.');
        } catch (DocumentReviewException) {
        }

        $fresh = $document->fresh();
        $this->assertSame(DriverDocumentStatus::Rejected, $fresh->status);
        $this->assertSame($first->id, $fresh->reviewed_by);
        $this->assertSame('Blurry', $fresh->rejection_reason);
    }
}
