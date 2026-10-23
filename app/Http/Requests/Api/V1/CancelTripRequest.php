<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/trips/{id}/cancel (the rider; the reason is optional) and POST /api/v1/driver/trips/{id}/cancel (the driver; the reason is
 * required: it is kept with the cancellation).
 */
class CancelTripRequest extends FormRequest
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
        return ['reason' => [$this->routeIs('driver.*') ? 'required' : 'nullable', 'string', 'min:3', 'max:500']];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return $reason === null ? null : trim($reason);
    }
}
