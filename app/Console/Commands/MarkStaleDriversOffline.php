<?php

namespace App\Console\Commands;

use App\Services\Drivers\StaleDriverSweeper;
use Illuminate\Console\Command;

class MarkStaleDriversOffline extends Command
{
    protected $signature = 'drivers:mark-stale-offline';

    protected $description = 'Mark online drivers with no recent ping offline and prune stale positions from Redis';

    public function handle(StaleDriverSweeper $sweeper): int
    {
        $result = $sweeper->sweep();

        $this->info("Marked {$result['marked_offline']} driver(s) offline, pruned {$result['pruned']} stale position(s).");

        return self::SUCCESS;
    }
}
