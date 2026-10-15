<?php

namespace App\Console\Commands;

use App\Services\Payments\WalletReconciler;
use Illuminate\Console\Command;

/**
 * Nightly check of every wallet's cached balance against its ledger (P6-T1, scheduled in routes/console.php). Each mismatch is logged at
 * critical level and the command fails, so the scheduler shows the run as failed. It changes nothing.
 */
class ReconcileWalletBalances extends Command
{
    protected $signature = 'wallets:reconcile';

    protected $description = 'Check every wallet balance against its ledger and alert on any mismatch';

    public function handle(WalletReconciler $reconciler): int
    {
        $mismatches = $reconciler->reconcile();

        if ($mismatches === []) {
            $this->info('Every wallet balance matches its ledger.');

            return self::SUCCESS;
        }

        $this->table(['wallet', 'user', 'currency', 'cached', 'ledger', 'difference'], array_map(fn (array $m) => array_values($m), $mismatches));
        $this->error(count($mismatches).' wallet balance(s) do not match their ledger.');

        return self::FAILURE;
    }
}
