<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DriverDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document as its owner sees it: what it is, where it stands, and why it was rejected. The file path, the document number and
 * who reviewed it never leave the server.
 *
 * @mixin DriverDocument
 */
class DriverDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'expires_at' => $this->expires_at?->toDateString(),
            'uploaded_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
