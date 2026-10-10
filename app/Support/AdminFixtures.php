<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Typed placeholder data for admin pages whose domain task is not built yet
 * (P13-T1 scope split). One file per page in database/fixtures/admin; each domain task
 * replaces its controller's fixture call with real queries and deletes the file.
 */
final class AdminFixtures
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    /**
     * @return array<string, mixed>
     */
    public function get(string $name): array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid fixture name [{$name}].");
        }

        return $this->loaded[$name] ??= $this->load($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $name): array
    {
        $path = database_path("fixtures/admin/{$name}.php");

        if (! is_file($path)) {
            throw new InvalidArgumentException("Admin fixture [{$name}] does not exist.");
        }

        return require $path;
    }
}
