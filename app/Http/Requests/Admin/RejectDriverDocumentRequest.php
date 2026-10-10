<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectDriverDocumentRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (drivers.verify).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // The driver sees this text, so it is required and bounded. Whitespace-only input is
        // trimmed to empty by the TrimStrings middleware and fails `required`.
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
