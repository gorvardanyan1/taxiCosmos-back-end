<?php

namespace Database\Factories;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows only: no file is written to the documents disk (tests that need one store it themselves).
 *
 * @extends Factory<DriverDocument>
 */
class DriverDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => DriverProfile::factory(),
            'type' => DriverDocumentType::License,
            'file_path' => 'documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ];
    }

    public function ofType(DriverDocumentType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function expiring(string $date): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => $date]);
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DriverDocumentStatus::Approved,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Photo is unreadable', ?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DriverDocumentStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
        ]);
    }
}
