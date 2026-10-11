<?php

namespace Tests\E2e;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lets a spec check what really reached the database (rows, audit entries, ciphertext). Read-only:
 * only SELECT / WITH, inside a READ ONLY transaction, with bindings.
 */
class E2eSqlCommand extends Command
{
    protected $signature = 'e2e:sql {query} {bindings=[]}';

    protected $description = 'Run one read-only SELECT against the e2e database and print the rows as JSON (e2e/testing only)';

    public function handle(): int
    {
        E2eGuard::assertSafe();

        $query = trim((string) $this->argument('query'));
        $bindings = json_decode((string) $this->argument('bindings'), true, flags: JSON_THROW_ON_ERROR);

        if (! preg_match('/^(select|with)\s/i', $query) || str_contains($query, ';')) {
            throw new InvalidArgumentException('Only a single SELECT/WITH statement is allowed.');
        }

        $rows = DB::transaction(function () use ($query, $bindings) {
            DB::statement('SET TRANSACTION READ ONLY');

            return DB::select($query, $bindings);
        });

        $this->line(json_encode($rows));

        return self::SUCCESS;
    }
}
