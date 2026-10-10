<?php

namespace App\Services\DriverDocuments;

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
            return DB::transaction(function () use ($driver, $type, $file, $vehicle, $documentNumber, $expiresAt, $path) {
                $document = new DriverDocument([
                    'type' => $type,
                    'vehicle_id' => $vehicle?->getKey(),
                    'document_number' => $documentNumber === null || trim($documentNumber) === '' ? null : trim($documentNumber),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'expires_at' => $expiresAt?->toDateString(),
                ]);
                $document->driver()->associate($driver)->save();

                // A new upload can move a rejected driver back to pending.
                $this->verification->sync($driver, null);

                return $document;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
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
            ['vehicle_id.in' => 'The vehicle does not belong to this driver.', 'vehicle_id.prohibited' => 'This document type does not belong to a vehicle.'],
        );

        $validator->validate();
    }
}
