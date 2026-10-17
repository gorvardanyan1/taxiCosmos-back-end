<?php

namespace App\Console\Commands;

use App\Services\Payments\TripCardPayments;
use Illuminate\Console\Command;

/**
 * Renews card holds that would lapse at the gateway before their trip ends (P6-T2, hourly in routes/console.php). A renewal that
 * fails is logged and keeps the old hold.
 */
class RefreshExpiringHolds extends Command
{
    protected $signature = 'payments:refresh-holds';

    protected $description = 'Renew card holds about to expire on trips that are still running';

    public function handle(TripCardPayments $payments): int
    {
        $this->info($payments->renewExpiringHolds().' hold(s) renewed.');

        return self::SUCCESS;
    }
}
