<?php

namespace Tests\E2e;

use App\Realtime\RealtimeEventType;
use App\Realtime\StreamClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * A clean slate for one Cypress spec: fresh schema + roles, empty cache (rate limits, permission
 * cache), no realtime events, no uploaded e2e files.
 */
class E2eResetCommand extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'Rebuild the e2e database and clear its cache, streams and files (e2e/testing only)';

    public function handle(): int
    {
        E2eGuard::assertSafe();

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Artisan::call('cache:clear');

        foreach (RealtimeEventType::cases() as $type) {
            app(StreamClient::class)->raw(['DEL', $type->streamKey()]);
        }

        Storage::disk(config('taxikosmos.documents.disk'))->deleteDirectory(E2eFixtures::FILE_DIRECTORY);

        // The e2e log (LOG_FILE=e2e.log) starts empty, so a spec reads only its own lines. Never the main log.
        $log = (string) config('logging.channels.single.path');
        if (basename($log) !== 'laravel.log' && is_file($log)) {
            file_put_contents($log, '');
        }

        $this->line(json_encode(['reset' => true]));

        return self::SUCCESS;
    }
}
