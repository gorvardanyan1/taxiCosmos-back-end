<?php

namespace App\Models;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use Database\Factories\DriverDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded driver or vehicle paper awaiting (or past) review. Status, reviewer and
 * rejection reason are set only by DriverDocumentReviewer; the file lives on the private
 * documents disk and is served only through signed URLs.
 */
#[Fillable(['type', 'vehicle_id', 'document_number', 'file_path', 'mime_type', 'size_bytes', 'expires_at'])]
#[Hidden(['document_number', 'file_path'])]
class DriverDocument extends Model
{
    /** @use HasFactory<DriverDocumentFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => DriverDocumentStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DriverDocumentType::class,
            'status' => DriverDocumentStatus::class,
            'document_number' => 'encrypted',
            'expires_at' => 'date',
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DriverProfile, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Past its expiry date today (UTC date), whatever the stored status says: the scheduled
     * expiry job may not have run yet.
     */
    public function isPastExpiry(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }
}
