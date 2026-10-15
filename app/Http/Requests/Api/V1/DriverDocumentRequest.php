<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DriverDocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/driver/documents (multipart). The file's type and size are checked on its content by the uploader, not here and not
 * by its name; vehicle papers name one of the driver's own vehicles.
 */
class DriverDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DriverDocumentType::class)],
            'file' => ['required', 'file'],
            'vehicle_id' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'document_number' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function type(): DriverDocumentType
    {
        return DriverDocumentType::from($this->validated('type'));
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->validated('expires_at');

        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }
}
