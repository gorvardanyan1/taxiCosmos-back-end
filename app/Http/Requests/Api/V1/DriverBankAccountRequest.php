<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/driver/bank-accounts: the holder, the bank and the account number (an IBAN or a local number). */
class DriverBankAccountRequest extends FormRequest
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
            'account_holder' => ['required', 'string', 'min:2', 'max:120'],
            'bank_name' => ['required', 'string', 'min:2', 'max:120'],
            // Spaces are allowed as typed ("AM00 1234 …"); the number itself is 8-34 letters and digits.
            'account_number' => ['required', 'string', 'regex:/^(?=(?:\s*[A-Za-z0-9]){8,34}\s*$)[A-Za-z0-9\s]+$/'],
            'swift' => ['nullable', 'string', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}(?:[A-Za-z0-9]{3})?$/'],
        ];
    }

    /**
     * @return array{account_holder: string, bank_name: string, account_number: string, swift: ?string}
     */
    public function account(): array
    {
        $data = $this->validated();

        return [
            'account_holder' => trim($data['account_holder']),
            'bank_name' => trim($data['bank_name']),
            'account_number' => preg_replace('/\s+/', '', $data['account_number']),
            'swift' => isset($data['swift']) ? strtoupper($data['swift']) : null,
        ];
    }
}
