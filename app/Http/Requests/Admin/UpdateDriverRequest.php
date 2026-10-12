<?php

namespace App\Http\Requests\Admin;

use App\Models\DriverProfile;
use App\Models\User;
use App\Support\E164PhoneNumber;
use App\Support\LicenseNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

class UpdateDriverRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (drivers.edit).
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
        $driver = DriverProfile::query()->find((int) $this->route('driver'));
        $userId = $driver?->user_id ?? 0;

        return [
            'reason' => ['required', 'string', 'max:500'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'required', 'string', 'max:32', $this->phoneRule($userId)],
            'license_number' => ['sometimes', 'required', 'string', 'max:64', $this->licenceRule($driver?->id ?? 0)],
            'license_expiry' => ['sometimes', 'required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2200-01-01'],
            'home_zone_id' => ['sometimes', 'nullable', 'integer', Rule::exists('zones', 'id')],
        ];
    }

    /**
     * A real phone number (parsed in the default region when written without +), not used by anyone else.
     */
    private function phoneRule(int $userId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($userId) {
            $normalised = is_string($value) ? E164PhoneNumber::tryNormalize($value) : null;

            if ($normalised === null || ! (new PhoneNumber($normalised))->isValid()) {
                $fail(__('The phone must be a valid phone number.'));

                return;
            }

            // Soft-deleted accounts keep their phone_hash (the unique index counts them), so they count too.
            if (User::query()->withTrashed()->wherePhone($normalised)->where('id', '!=', $userId)->exists()) {
                $fail(__('Another account already uses this phone number.'));
            }
        };
    }

    /**
     * A licence number no other driver holds (case and spaces ignored).
     */
    private function licenceRule(int $profileId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($profileId) {
            if (is_string($value) && LicenseNumber::normalize($value) !== '' && DriverProfile::query()->whereLicenseNumber($value)->where('id', '!=', $profileId)->exists()) {
                $fail(__('Another driver already has this licence number.'));
            }
        };
    }
}
