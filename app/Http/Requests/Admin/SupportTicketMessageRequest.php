<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/support-tickets/{id}/messages: a reply to the requester, or an internal note (staff only). Route middleware: support.manage. */
class SupportTicketMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
        ];
    }

    public function internal(): bool
    {
        return $this->boolean('internal');
    }
}
