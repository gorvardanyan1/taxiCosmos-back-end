<?php

namespace App\Support\Pii;

use App\Support\E164PhoneNumber;
use App\Support\LicenseNumber;

/**
 * Registry of every encrypted personal-data column (docs/security.md). `pii:reencrypt` walks it,
 * and PiiColumnsGuardTest fails when a column that looks sensitive is missing here, so a later task
 * (driver documents, two-factor secrets, gateway credentials) cannot ship one unencrypted.
 */
final class PiiColumns
{
    /**
     * @return list<PiiColumn>
     */
    public static function all(): array
    {
        return [
            // Searchable: mobile login identifier and admin search.
            new PiiColumn('users', 'phone', 'phone_hash', E164PhoneNumber::class),
            // Searchable: the verification workflow looks drivers up by license number.
            new PiiColumn('driver_profiles', 'license_number', 'license_number_hash', LicenseNumber::class),
            // Not searchable (no blind index): shown masked from account_last4, revealed on demand.
            new PiiColumn('driver_bank_accounts', 'account_number'),
        ];
    }

    /**
     * Columns whose names match sensitiveNamePattern() but are deliberately not encrypted: the
     * blind indexes themselves (a keyed hash, not the value). Other plaintext columns next to PII
     * (driver_bank_accounts.account_last4 for the masked display, swift, users.password which is a
     * bcrypt hash) do not match the pattern and are described in docs/security.md.
     *
     * @return list<string> "table.column"
     */
    public static function plaintextByDesign(): array
    {
        return [
            'users.phone_hash',
            'driver_profiles.license_number_hash',
        ];
    }

    /**
     * Name patterns that mark a column as personal or secret data.
     */
    public static function sensitiveNamePattern(): string
    {
        return '/(phone|license_number|document_number|id_number|account_number|iban|two_factor|secret|credential|api_key|private_key|passport)/i';
    }
}
