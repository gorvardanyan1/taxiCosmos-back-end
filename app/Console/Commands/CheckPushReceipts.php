<?php

namespace App\Console\Commands;

use App\Services\Push\PushReceiptChecker;
use Illuminate\Console\Command;

class CheckPushReceipts extends Command
{
    protected $signature = 'push:check-receipts';

    protected $description = 'Read the push delivery receipts and revoke devices the push service says are no longer registered';

    public function handle(PushReceiptChecker $checker): int
    {
        $result = $checker->check();

        $this->info("Read {$result['checked']} receipt(s), revoked {$result['revoked']} device(s), dropped {$result['expired']} old ticket(s).");

        return self::SUCCESS;
    }
}
