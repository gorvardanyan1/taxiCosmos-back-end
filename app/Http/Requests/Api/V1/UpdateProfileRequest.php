<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/me: the name, email and language of the signed-in person. Only the fields that are sent change. The phone
 * has its own flow, and nothing here can name another user.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // One spelling per address, so "Ann@Example.com" and "ann@example.com" are the same account's.
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($user) {
                // Soft-deleted accounts keep their address, so they count too.
                if (is_string($value) && User::withTrashed()->whereRaw('lower(email) = ?', [$value])->whereKeyNot($user->getKey())->exists()) {
                    $fail(__('This email address is already in use.'));
                }
            }],
            'locale' => ['sometimes', 'required', 'string', Rule::in(Locales::supported())],
        ];
    }

    /**
     * @return array{name?: string, email?: ?string, locale?: string}
     */
    public function changes(): array
    {
        return $this->safe()->only(['name', 'email', 'locale']);
    }
}
