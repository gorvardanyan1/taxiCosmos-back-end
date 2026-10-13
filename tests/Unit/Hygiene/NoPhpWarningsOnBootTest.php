<?php

namespace Tests\Unit\Hygiene;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * QA-18 (P5-T1): `use Throwable;` in the namespace-less bootstrap/app.php made PHP print a warning on every artisan command and
 * every request. A file that runs on every boot must compile without warnings or notices, whatever display_errors is.
 */
class NoPhpWarningsOnBootTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function bootFiles(): array
    {
        $base = dirname(__DIR__, 3);
        $files = ['bootstrap/app.php' => $base.'/bootstrap/app.php'];

        foreach (['routes', 'config', 'app/Providers', 'app/Exceptions'] as $dir) {
            foreach (glob("{$base}/{$dir}/*.php") ?: [] as $file) {
                $files[substr($file, strlen($base) + 1)] = $file;
            }
        }

        return array_map(fn (string $file) => [$file], $files);
    }

    #[DataProvider('bootFiles')]
    public function test_the_file_compiles_without_warnings(string $file): void
    {
        $lint = new Process([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-l', $file]);
        $lint->run();

        $this->assertTrue($lint->isSuccessful(), $lint->getErrorOutput());
        $this->assertStringNotContainsString('Warning', $lint->getOutput().$lint->getErrorOutput(), "{$file} must not warn when PHP compiles it");
        $this->assertStringNotContainsString('Notice', $lint->getOutput().$lint->getErrorOutput());
        $this->assertStringNotContainsString('Deprecated', $lint->getOutput().$lint->getErrorOutput());
    }
}
