<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Reads a config value the way a deployment would: a fresh `artisan config:show` process with the given
 * environment variables, so a default that depends on APP_ENV or an .env key is tested as shipped.
 */
trait ShowsConfig
{
    /**
     * @param  array<string, string>  $env
     * @return array<string, string> the entries under the key (`secure => true`, `0 => first item`)
     */
    protected function configShow(string $key, array $env = []): array
    {
        $process = new Process([PHP_BINARY, 'artisan', 'config:show', $key, '--no-ansi'], base_path(), $env);
        $process->mustRun();

        preg_match_all('/^\s*(\S+) \.+ ?(.*)$/m', $process->getOutput(), $matches, PREG_SET_ORDER);

        // The first line names the key itself ("security.admin_ip_allowlist .. "); the rest are its entries.
        $lines = array_column($matches, 2, 1);
        unset($lines[$key]);

        return array_map('trim', $lines);
    }
}
