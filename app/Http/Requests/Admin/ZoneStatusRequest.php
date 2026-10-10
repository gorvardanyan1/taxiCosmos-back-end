<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deactivate / reactivate a zone; the reason is optional but kept in the audit log when given.
 */
class ZoneStatusRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (zones.manage).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
