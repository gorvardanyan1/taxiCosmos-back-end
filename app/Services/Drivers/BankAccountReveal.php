<?php

namespace App\Services\Drivers;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Shows a driver's full bank account number to an admin who may (drivers.reveal_bank_account, finance).
 * Every reveal is written to the activity log first (who, which account, which driver); the log keeps
 * the last four digits only, never the number. Anywhere else the account is masked.
 */
final class BankAccountReveal
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function reveal(User $actor, DriverProfile $driver, DriverBankAccount $account): string
    {
        abort_unless($account->driver_id === $driver->id, 404);

        $this->audit->record(
            actor: $actor, action: 'driver.bank_account.revealed', target: $account,
            context: ['driver' => sprintf('D-%04d', $driver->id), 'account_last4' => $account->account_last4],
        );

        return $account->account_number;
    }
}
