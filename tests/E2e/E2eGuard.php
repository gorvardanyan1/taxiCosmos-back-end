<?php

namespace Tests\E2e;

use RuntimeException;

/**
 * The Cypress helpers rebuild and edit the database. They run only where that is harmless: the
 * application environment must be `e2e` (or `testing`) AND the connected database must be a
 * throwaway one (name ending in `_e2e` or `_test`). Both checks, so a mis-set APP_ENV alone can
 * never reach the dev, staging or production data.
 */
final class E2eGuard
{
    public static function assertSafe(): void
    {
        $environment = app()->environment();
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! in_array($environment, ['e2e', 'testing'], true)) {
            throw new RuntimeException("E2E helpers refuse to run in the \"{$environment}\" environment (only e2e or testing).");
        }

        if (! str_ends_with($database, '_e2e') && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("E2E helpers refuse to touch the \"{$database}\" database (only *_e2e or *_test).");
        }
    }
}
