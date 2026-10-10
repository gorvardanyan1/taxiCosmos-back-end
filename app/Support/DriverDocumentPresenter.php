<?php

namespace App\Support;

use App\Models\DriverDocument;
use Illuminate\Support\Facades\URL;

/**
 * Shapes a document for the admin Documents tab. The file is only linked through a short-lived
 * signed URL; the stored path and the (encrypted) document number never leave the server.
 */
final class DriverDocumentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DriverDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'status' => $document->status->value,
            'expires_at' => $document->expires_at?->toDateString(),
            'rejection_reason' => $document->rejection_reason,
            'mime_type' => $document->mime_type,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewer' => $document->reviewer?->name,
            'file_url' => self::fileUrl($document),
        ];
    }

    public static function fileUrl(DriverDocument $document): string
    {
        return URL::temporarySignedRoute(
            'admin.drivers.documents.file',
            now()->addMinutes((int) config('taxikosmos.documents.url_ttl_minutes')),
            ['driver' => $document->driver_id, 'document' => $document->id],
            absolute: false,
        );
    }
}
