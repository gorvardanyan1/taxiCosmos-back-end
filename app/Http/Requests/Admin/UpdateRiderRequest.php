<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\E164PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

class UpdateRiderRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (riders.edit).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The sign-in form lower-cases emails; do the same so one address cannot exist in two spellings.
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Only the fields sent change; the reason is always required (it goes to the audit log).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rider = (int) $this->route('rider');

        return [
            'reason' => ['required', 'string', 'max:500'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($rider)],
            'phone' => ['sometimes', 'required', 'string', 'max:32', $this->phoneRule($rider)],
            'locale' => ['sometimes', 'nullable', Rule::in(config('taxikosmos.locales'))],
        ];
    }

    /**
     * A real phone number (parsed in the default region when written without +), not used by anyone else.
     */
    private function phoneRule(int $rider): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($rider) {
            $normalised = is_string($value) ? E164PhoneNumber::tryNormalize($value) : null;

            if ($normalised === null || ! (new PhoneNumber($normalised))->isValid()) {
                $fail('The phone must be a valid phone number.');

                return;
            }

            if (User::query()->wherePhone($normalised)->where('id', '!=', $rider)->exists()) {
                $fail('Another account already uses this phone number.');
            }
        };
    }
}
