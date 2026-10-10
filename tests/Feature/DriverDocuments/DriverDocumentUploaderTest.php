<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use App\Services\DriverDocuments\DriverDocumentUploader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class DriverDocumentUploaderTest extends DriverDocumentTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function uploader(): DriverDocumentUploader
    {
        return app(DriverDocumentUploader::class);
    }

    /** A real file on disk, so the type is sniffed from its content like in production. */
    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'license.pdf'): UploadedFile
    {
        return $this->file($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('taxikosmos.documents.disk'));
    }

    public function test_a_pdf_is_stored_privately_and_recorded_as_pending(): void
    {
        $driver = DriverProfile::factory()->create();

        $document = $this->uploader()->upload($driver, DriverDocumentType::License, $this->pdf('my passport scan.pdf'), documentNumber: ' AB-1234567 ', expiresAt: now()->addYear());

        $fresh = DriverDocument::find($document->id);
        $this->assertSame($driver->id, $fresh->driver_id);
        $this->assertSame(DriverDocumentType::License, $fresh->type);
        $this->assertSame(DriverDocumentStatus::Pending, $fresh->status);
        $this->assertSame('application/pdf', $fresh->mime_type);
        $this->assertSame(now()->addYear()->toDateString(), $fresh->expires_at->toDateString());
        $this->assertSame('AB-1234567', $fresh->document_number);
        $this->assertTrue($this->disk()->exists($fresh->file_path));
        $this->assertStringStartsWith("drivers/{$driver->id}/", $fresh->file_path);
        $this->assertStringEndsWith('.pdf', $fresh->file_path);
        $this->assertStringNotContainsString('passport', $fresh->file_path, 'The client file name is never reused.');
        $this->assertSame($this->pdf()->getSize(), $fresh->size_bytes);
    }

    public function test_the_document_number_is_encrypted_in_the_database(): void
    {
        $document = $this->uploader()->upload(DriverProfile::factory()->create(), DriverDocumentType::IdCard, $this->pdf(), documentNumber: 'AB-1234567');

        $raw = DB::table('driver_documents')->where('id', $document->id)->value('document_number');

        $this->assertStringNotContainsString('1234567', $raw);
        $this->assertSame('AB-1234567', DriverDocument::find($document->id)->document_number);
        $this->assertArrayNotHasKey('document_number', DriverDocument::find($document->id)->toArray());
    }

    public function test_a_blank_document_number_is_stored_as_null(): void
    {
        $document = $this->uploader()->upload(DriverProfile::factory()->create(), DriverDocumentType::IdCard, $this->pdf(), documentNumber: '   ');

        $this->assertNull(DriverDocument::find($document->id)->document_number);
    }

    public function test_png_jpeg_and_webp_images_are_accepted_by_content(): void
    {
        $driver = DriverProfile::factory()->create();

        $document = $this->uploader()->upload($driver, DriverDocumentType::IdCard, $this->file('front.png', base64_decode(self::PNG)));

        $this->assertSame('image/png', $document->mime_type);
        $this->assertStringEndsWith('.png', $document->file_path);
    }

    #[DataProvider('disguisedFiles')]
    public function test_a_file_is_judged_by_its_content_not_its_name_or_claimed_type(string $name, string $content): void
    {
        $driver = DriverProfile::factory()->create();

        try {
            $this->uploader()->upload($driver, DriverDocumentType::License, $this->file($name, $content));
            $this->fail('The file should have been refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }

        $this->assertSame(0, DriverDocument::count());
        $this->assertSame([], $this->disk()->allFiles());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function disguisedFiles(): array
    {
        return [
            'php script named .jpg' => ['photo.jpg', '<?php system($_GET["c"]);'],
            'html named .pdf' => ['scan.pdf', '<html><script>alert(1)</script></html>'],
            'svg named .png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'plain text' => ['license.pdf', 'just some text'],
            'empty file' => ['license.pdf', ''],
        ];
    }

    public function test_the_size_limit_is_enforced_at_the_boundary(): void
    {
        config(['taxikosmos.documents.max_kilobytes' => 1]);
        $driver = DriverProfile::factory()->create();
        $header = "%PDF-1.4\n";

        $ok = $this->uploader()->upload($driver, DriverDocumentType::License, $this->file('a.pdf', $header.str_repeat('a', 1024 - strlen($header))));
        $this->assertSame(1024, $ok->size_bytes);

        $this->expectException(ValidationException::class);
        $this->uploader()->upload($driver, DriverDocumentType::License, $this->file('b.pdf', $header.str_repeat('a', 1025 - strlen($header))));
    }

    public function test_an_expiry_date_in_the_past_is_refused_but_today_is_fine(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $driver = DriverProfile::factory()->create();

        $this->uploader()->upload($driver, DriverDocumentType::License, $this->pdf(), expiresAt: now());

        $this->expectException(ValidationException::class);
        $this->uploader()->upload($driver, DriverDocumentType::License, $this->pdf(), expiresAt: now()->subDay());
    }

    public function test_a_vehicle_paper_can_name_only_the_drivers_own_vehicle(): void
    {
        $driver = DriverProfile::factory()->create();
        $own = Vehicle::factory()->for($driver, 'driver')->create();
        $foreign = Vehicle::factory()->create();

        $document = $this->uploader()->upload($driver, DriverDocumentType::Insurance, $this->pdf(), vehicle: $own);
        $this->assertSame($own->id, $document->fresh()->vehicle_id);

        try {
            $this->uploader()->upload($driver, DriverDocumentType::Insurance, $this->pdf(), vehicle: $foreign);
            $this->fail('Another driver\'s vehicle must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['The vehicle does not belong to this driver.'], $e->errors()['vehicle_id']);
        }

        $this->assertSame(1, DriverDocument::count());
    }

    public function test_a_driver_paper_cannot_be_attached_to_a_vehicle(): void
    {
        $driver = DriverProfile::factory()->create();
        $vehicle = Vehicle::factory()->for($driver, 'driver')->create();

        $this->expectException(ValidationException::class);
        $this->uploader()->upload($driver, DriverDocumentType::License, $this->pdf(), vehicle: $vehicle);
    }

    public function test_uploading_after_a_rejection_puts_the_driver_back_to_pending(): void
    {
        $driver = DriverProfile::factory()->create(['verification_status' => DriverVerificationStatus::Rejected]);
        $this->pendingDocument($driver, DriverDocumentType::License, ['status' => DriverDocumentStatus::Rejected, 'rejection_reason' => 'Blurry']);

        $this->uploader()->upload($driver, DriverDocumentType::License, $this->pdf());

        $this->assertSame(DriverVerificationStatus::Pending, $driver->fresh()->verification_status);
    }

    public function test_a_failed_save_removes_the_stored_file(): void
    {
        $driver = DriverProfile::factory()->create();
        DB::statement('ALTER TABLE driver_documents ADD CONSTRAINT force_failure CHECK (size_bytes < 0)');

        try {
            $this->uploader()->upload($driver, DriverDocumentType::License, $this->pdf());
            $this->fail('The insert should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('force_failure', $e->getMessage());
        }

        $this->assertSame([], $this->disk()->allFiles(), 'No orphan file stays behind.');
    }
}
