<?php

namespace App\Services\Payments;

use App\Enums\WalletEntryType;
use App\Enums\WalletReason;
use App\Exceptions\WalletException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way money enters or leaves a wallet (P6-T1). Each call is one database transaction under the wallet's row lock that inserts
 * the append-only ledger row and moves the cached balance by the same amount, so the two cannot drift apart (and `wallets:reconcile`
 * proves they have not). A movement caused by something (a refund, a trip) takes that cause as its reference: posting the same cause
 * twice returns the first entry and moves nothing, so a retried refund cannot pay out twice. Amounts are integer minor units.
 */
final class WalletLedger
{
    /** The user's wallet in this currency, created empty on first use. */
    public function walletFor(User $user, string $currency): Wallet
    {
        $wallet = Wallet::query()->where('user_id', $user->getKey())->where('currency', $currency)->first();

        if ($wallet === null) {
            // Two first uses at once: the unique (user, currency) key keeps one, and both end up with it.
            Wallet::query()->insertOrIgnore(['user_id' => $user->getKey(), 'currency' => $currency, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $wallet = Wallet::query()->where('user_id', $user->getKey())->where('currency', $currency)->firstOrFail();
        }

        return $wallet;
    }

    public function credit(Wallet $wallet, int $amount, WalletReason $reason, ?string $referenceType = null, ?int $referenceId = null, ?User $createdBy = null): WalletTransaction
    {
        return $this->post(WalletEntryType::Credit, $wallet, $amount, $reason, $referenceType, $referenceId, $createdBy);
    }

    /**
     * @throws WalletException when the balance is lower than the amount
     */
    public function debit(Wallet $wallet, int $amount, WalletReason $reason, ?string $referenceType = null, ?int $referenceId = null, ?User $createdBy = null): WalletTransaction
    {
        return $this->post(WalletEntryType::Debit, $wallet, $amount, $reason, $referenceType, $referenceId, $createdBy);
    }

    private function post(WalletEntryType $type, Wallet $wallet, int $amount, WalletReason $reason, ?string $referenceType, ?int $referenceId, ?User $createdBy): WalletTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('A wallet movement is a positive amount of minor units.');
        }

        if (($referenceType === null) !== ($referenceId === null)) {
            throw new InvalidArgumentException('A reference needs both its type and its id.');
        }

        return DB::transaction(function () use ($type, $wallet, $amount, $reason, $referenceType, $referenceId, $createdBy) {
            $locked = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if ($referenceId !== null) {
                $earlier = WalletTransaction::query()->where('wallet_id', $locked->id)->where('type', $type->value)->where('reference_type', $referenceType)->where('reference_id', $referenceId)->first();

                if ($earlier !== null) {
                    return $earlier;
                }
            }

            if ($type === WalletEntryType::Debit && $locked->balance < $amount) {
                throw WalletException::insufficientBalance();
            }

            if ($type === WalletEntryType::Credit && $amount > PHP_INT_MAX - $locked->balance) {
                throw WalletException::overflow();
            }

            $entry = new WalletTransaction;
            $entry->forceFill([
                'wallet_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $createdBy?->getKey(),
                'created_at' => now(),
            ])->save();

            $locked->forceFill(['balance' => $type === WalletEntryType::Credit ? $locked->balance + $amount : $locked->balance - $amount])->save();
            $wallet->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }
}
