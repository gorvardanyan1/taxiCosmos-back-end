<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks every wallet's cached balance against the sum of its ledger (P6-T1): credits minus debits, recalculated in the database in one
 * grouped query. A wallet that does not match is reported, with both numbers and the difference, at critical level so it reaches the
 * on-call alert. It is never "fixed" here: the ledger is the record, a mismatch means a bug or tampering and a person must look.
 */
final class WalletReconciler
{
    /**
     * @return list<array{wallet_id: int, user_id: int, currency: string, cached: int, ledger: int, difference: int}>
     */
    public function mismatches(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT w.id AS wallet_id, w.user_id, w.currency, w.balance AS cached,
                   COALESCE(SUM(CASE t.type WHEN 'credit' THEN t.amount ELSE -t.amount END), 0)::bigint AS ledger
            FROM wallets w
            LEFT JOIN wallet_transactions t ON t.wallet_id = w.id
            GROUP BY w.id, w.user_id, w.currency, w.balance
            HAVING w.balance <> COALESCE(SUM(CASE t.type WHEN 'credit' THEN t.amount ELSE -t.amount END), 0)
            ORDER BY w.id
        SQL);

        return array_map(fn (object $row) => [
            'wallet_id' => (int) $row->wallet_id, 'user_id' => (int) $row->user_id, 'currency' => $row->currency,
            'cached' => (int) $row->cached, 'ledger' => (int) $row->ledger, 'difference' => (int) $row->cached - (int) $row->ledger,
        ], $rows);
    }

    /**
     * Run the check and alert on every mismatch.
     *
     * @return list<array{wallet_id: int, user_id: int, currency: string, cached: int, ledger: int, difference: int}>
     */
    public function reconcile(): array
    {
        $mismatches = $this->mismatches();

        foreach ($mismatches as $mismatch) {
            Log::critical('A wallet balance does not match its ledger.', $mismatch);
        }

        return $mismatches;
    }
}
