<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Support\DriverDocumentPresenter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

class DriverDocumentFileAccessTest extends DriverDocumentTestCase
{
    private function storedDocument(?DriverProfile $driver = null, string $content = '%PDF-1.4 secret'): DriverDocument
    {
        $document = $this->pendingDocument($driver, attributes: ['file_path' => 'drivers/1/abc.pdf', 'mime_type' => 'application/pdf']);
        Storage::disk(config('taxikosmos.documents.disk'))->put($document->file_path, $content);

        return $document;
    }

    public function test_a_signed_link_streams_the_file_with_safe_headers(): void
    {
        $document = $this->storedDocument();
        $url = DriverDocumentPresenter::fileUrl($document);

        $response = $this->actingAs($this->admin(AdminRole::Finance))->get($url)->assertOk();

        $this->assertSame('%PDF-1.4 secret', $response->streamedContent());
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_the_file_is_not_reachable_without_a_signature(): void
    {
        $document = $this->storedDocument();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/admin/drivers/{$document->driver_id}/documents/{$document->id}/file")->assertForbidden();
    }

    public function test_a_tampered_signature_or_changed_document_id_is_refused(): void
    {
        $document = $this->storedDocument();
        $other = $this->storedDocument();
        $admin = $this->admin();
        $url = DriverDocumentPresenter::fileUrl($document);

        $this->actingAs($admin)->get($url.'0')->assertForbidden();
        $this->actingAs($admin)->get(str_replace("/documents/{$document->id}/", "/documents/{$other->id}/", $url))->assertForbidden();
    }

    public function test_the_link_expires_after_the_configured_minutes(): void
    {
        $this->travelTo('2026-10-10 10:00:00');
        config(['taxikosmos.documents.url_ttl_minutes' => 5]);
        $document = $this->storedDocument();
        $url = DriverDocumentPresenter::fileUrl($document);
        $admin = $this->admin();

        $this->travelTo('2026-10-10 10:04:59');
        $this->actingAs($admin)->get($url)->assertOk();

        $this->travelTo('2026-10-10 10:05:01');
        $this->actingAs($admin)->get($url)->assertForbidden();
    }

    public function test_a_valid_link_is_useless_without_an_admin_session(): void
    {
        $url = DriverDocumentPresenter::fileUrl($this->storedDocument());

        $this->get($url)->assertRedirect('/login');
    }

    public function test_an_admin_without_drivers_view_cannot_use_a_valid_link(): void
    {
        $url = DriverDocumentPresenter::fileUrl($this->storedDocument());
        Role::findByName(AdminRole::Dispatcher->value)->revokePermissionTo(AdminPermission::DriversView->value);
        $noPermission = $this->admin(AdminRole::Dispatcher);

        $this->actingAs($noPermission)->get($url)->assertForbidden();
    }

    public function test_a_link_for_the_wrong_driver_is_a_404_even_when_correctly_signed(): void
    {
        $document = $this->storedDocument();
        $other = DriverProfile::factory()->create();
        $forged = URL::temporarySignedRoute('admin.drivers.documents.file', now()->addMinute(), ['driver' => $other->id, 'document' => $document->id], absolute: false);

        $this->actingAs($this->admin())->get($forged)->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_not_a_server_error(): void
    {
        $document = $this->pendingDocument(attributes: ['file_path' => 'drivers/1/gone.pdf']);

        $this->actingAs($this->admin())->get(DriverDocumentPresenter::fileUrl($document))->assertNotFound();
    }

    public function test_the_documents_disk_is_private_and_has_no_public_url(): void
    {
        $config = config('filesystems.disks.'.config('taxikosmos.documents.disk'));

        $this->assertSame('private', $config['visibility']);
        $this->assertArrayNotHasKey('url', $config);
        $this->assertNotSame(config('filesystems.disks.public.root'), $config['root']);
        $this->assertStringNotContainsString(public_path(), $config['root']);
    }

    public function test_the_driver_page_links_files_only_through_expiring_signed_urls(): void
    {
        $this->travelTo('2026-10-10 10:00:00');
        $document = $this->storedDocument();
        $document->forceFill(['document_number' => 'AB-1234567'])->save();

        $this->actingAs($this->admin())->get("/admin/drivers/{$document->driver_id}?tab=documents")
            ->assertInertia(function (Assert $page) use ($document) {
                $page->where('driver.documents.0.id', $document->id)
                    ->where('driver.documents.0.mime_type', 'application/pdf')
                    ->missing('driver.documents.0.file_path')
                    ->missing('driver.documents.0.document_number');

                $props = $page->toArray()['props']['driver']['documents'][0];
                $this->assertMatchesRegularExpression(
                    "#^/admin/drivers/{$document->driver_id}/documents/{$document->id}/file\\?expires=\\d+&signature=[a-f0-9]{64}$#",
                    $props['file_url'],
                );
                $this->assertSame(now()->addMinutes(5)->timestamp, (int) explode('expires=', explode('&', $props['file_url'])[0])[1]);
            });
    }
}
