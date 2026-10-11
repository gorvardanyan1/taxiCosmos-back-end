<?php

namespace Tests\E2e;

use Illuminate\Console\Command;

/**
 * Creates (or changes) the data a spec needs through a named, whitelisted fixture and prints the
 * result as JSON. See E2eFixtures for the list.
 */
class E2eMakeCommand extends Command
{
    protected $signature = 'e2e:make {fixture} {attributes=[]}';

    protected $description = 'Run a named e2e fixture and print its result as JSON (e2e/testing only)';

    public function handle(E2eFixtures $fixtures): int
    {
        E2eGuard::assertSafe();

        $attributes = json_decode((string) $this->argument('attributes'), true, flags: JSON_THROW_ON_ERROR);

        $this->line(json_encode($fixtures->run((string) $this->argument('fixture'), $attributes)));

        return self::SUCCESS;
    }
}
