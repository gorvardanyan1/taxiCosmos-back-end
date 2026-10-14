<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/support-tickets/{id}/assign: an admin id, or null to take the ticket off its assignee. */
class AssignSupportTicketRequest extends FormRequest
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
        return ['assignee_id' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
