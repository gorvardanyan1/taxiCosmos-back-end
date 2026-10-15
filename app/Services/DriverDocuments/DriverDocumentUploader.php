<?php

namespace App\Services\DriverDocuments;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores a driver's document on the private documents disk and records it as pending review.
 * The mobile onboarding endpoint (P4-T4) calls this; it validates the file by its real content
 * (not the client-sent name or type) and its size, and never keeps the client's file name.
 */
final class DriverDocumentUploader
{
    public function __construct(private readonly DriverVerificationSync $verification) {}

    /**
     * @throws ValidationException
     */
    public function upload(
        DriverProfile $driver,
        DriverDocumentType $type,
        UploadedFile $file,
        ?Vehicle $vehicle = null,
        ?string $documentNumber = null,
        ?CarbonInterface $expiresAt = null,
    ): DriverDocument {
        $this->validate($driver, $type, $file, $vehicle, $expiresAt);

        $disk = Storage::disk((string) config('taxikosmos.documents.disk'));
        // putFile picks a random name and an extension from the file's content.
        $path = $disk->putFile("drivers/{$driver->getKey()}", $file, 'private');

        try {
            $replaced = [];
            $document = DB::transaction(function () use ($driver, $type, $file, $vehicle, $documentNumber, $expiresAt, $path, &$replaced) {
                $document = new DriverDocument([
                    'type' => $type,
                    'vehicle_id' => $vehicle?->getKey(),
                    'document_number' => $documentNumber === null || trim($documentNumber) === '' ? null : trim($documentNumber),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'expires_at' => $expiresAt?->toDateString(),
                ]);
                // Two uploads of the same paper at once queue up here, so the later one replaces the earlier.
                DriverProfile::query()->whereKey($driver->getKey())->lockForUpdate()->first();
                $document->driver()->associate($driver)->save();
                $replaced = $this->replaceEarlier($driver, $type, $vehicle, $document);

                // A new upload can move a rejected driver back to pending.
                $this->verification->sync($driver, null);

                return $document;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        // The replaced ones are gone for good once the new one is saved.
        $disk->delete($replaced);

        return $document;
    }

    /**
     * The driver's earlier document of the same type (and vehicle) that is still pending or was rejected is replaced by the new
     * one: its row is kept for the audit trail but stops counting. An approved document stays until its replacement is approved.
     *
     * @return list<string> the replaced files, to delete after the commit
     */
    private function replaceEarlier(DriverProfile $driver, DriverDocumentType $type, ?Vehicle $vehicle, DriverDocument $new): array
    {
        $earlier = DriverDocument::query()
            ->where('driver_id', $driver->getKey())->where('type', $type->value)->whereKeyNot($new->getKey())
            ->when($vehicle === null, fn ($query) => $query->whereNull('vehicle_id'), fn ($query) => $query->where('vehicle_id', $vehicle->getKey()))
            ->whereIn('status', [DriverDocumentStatus::Pending->value, DriverDocumentStatus::Rejected->value])
            ->get();

        $paths = $earlier->pluck('file_path')->all();
        $earlier->each->delete();

        return $paths;
    }

    private function validate(DriverProfile $driver, DriverDocumentType $type, UploadedFile $file, ?Vehicle $vehicle, ?CarbonInterface $expiresAt): void
    {
        $validator = Validator::make(
            ['file' => $file, 'expires_at' => $expiresAt?->toDateString(), 'vehicle_id' => $vehicle?->getKey()],
            [
                'file' => ['required', 'file', 'max:'.config('taxikosmos.documents.max_kilobytes'), 'mimetypes:'.implode(',', config('taxikosmos.documents.mime_types'))],
                'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
                'vehicle_id' => $type->isVehicleDocument()
                    ? ['nullable', Rule::in([$vehicle?->driver_id === $driver->getKey() ? $vehicle->getKey() : null])]
                    : ['prohibited'],
            ],
            ['vehicle_id.in' => __('The vehicle does not belong to this driver.'), 'vehicle_id.prohibited' => __('This document type does not belong to a vehicle.')],
        );

        $validator->validate();
    }
}
