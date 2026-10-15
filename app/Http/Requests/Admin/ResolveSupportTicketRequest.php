<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/support-tickets/{id}/resolve: the resolution is required (the requester reads it). */
class ResolveSupportTicketRequest extends FormRequest
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
        return ['resolution' => ['required', 'string', 'min:1', 'max:2000']];
    }
}
