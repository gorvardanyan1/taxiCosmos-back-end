<?php

namespace App\Services\DriverDocuments;

use App\Enums\DriverDocumentType;
use RuntimeException;

/**
 * The document types a driver needs approved to be verified (config taxikosmos.documents.required).
 */
final class RequiredDocuments
{
    /**
     * @return list<DriverDocumentType>
     */
    public function types(): array
    {
        $types = array_map(
            fn (string $value) => DriverDocumentType::tryFrom($value)
                ?? throw new RuntimeException("DRIVER_REQUIRED_DOCUMENTS contains an unknown document type: {$value}."),
            (array) config('taxikosmos.documents.required'),
        );

        // With nothing required, "all required documents approved" would be vacuously true and the
        // first review would verify the driver.
        if ($types === []) {
            throw new RuntimeException('DRIVER_REQUIRED_DOCUMENTS must list at least one document type.');
        }

        return array_values(array_unique($types, SORT_REGULAR));
    }
}
