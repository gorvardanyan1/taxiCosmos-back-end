<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspend / reactivate a rider: the reason is required and goes to the audit log (the suspend
 * reason is also kept on the account).
 */
class RiderStatusRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (riders.suspend).
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
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
