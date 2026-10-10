<?php

namespace Tests\Feature\DriverDocuments;

use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Admin\AdminTestCase;

abstract class DriverDocumentTestCase extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('taxikosmos.documents.disk'));
        config(['taxikosmos.documents.required' => ['license', 'id_card']]);
    }

    protected function pendingDocument(?DriverProfile $driver = null, DriverDocumentType $type = DriverDocumentType::License, array $attributes = []): DriverDocument
    {
        return DriverDocument::factory()
            ->for($driver ?? DriverProfile::factory()->create(), 'driver')
            ->ofType($type)
            ->create($attributes);
    }

    protected function approveUrl(DriverDocument $document): string
    {
        return "/admin/drivers/{$document->driver_id}/documents/{$document->id}/approve";
    }

    protected function rejectUrl(DriverDocument $document): string
    {
        return "/admin/drivers/{$document->driver_id}/documents/{$document->id}/reject";
    }
}
