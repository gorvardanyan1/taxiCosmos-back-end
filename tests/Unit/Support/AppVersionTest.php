<?php

namespace Tests\Unit\Support;

use App\Support\AppVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P14-T4: what counts as an app version, and how two are ordered.
 */
class AppVersionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function versions(): array
    {
        return [
            'three parts' => ['1.4.2', '1.4.2'],
            'two parts mean .0' => ['1.4', '1.4.0'],
            'surrounding spaces are ignored' => [' 2.0.1 ', '2.0.1'],
            'zero' => ['0.0.0', '0.0.0'],
            'multi-digit parts' => ['10.20.30', '10.20.30'],
        ];
    }

    #[DataProvider('versions')]
    public function test_it_reads_the_versions_the_stores_use(string $given, string $expected): void
    {
        $this->assertSame($expected, (string) AppVersion::parse($given));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function notVersions(): array
    {
        return [
            'nothing' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'one part' => ['1'],
            'four parts' => ['1.2.3.4'],
            'a build suffix' => ['1.2.3-beta.1'],
            'a v prefix' => ['v1.2.3'],
            'letters' => ['abc'],
            'a negative part' => ['1.-2.3'],
            'a part that is far too long' => ['1.2.99999'],
            'sql' => ["1.2.3'; DROP TABLE users;--"],
            'a trailing dot' => ['1.2.'],
        ];
    }

    #[DataProvider('notVersions')]
    public function test_anything_else_is_not_a_version(?string $given): void
    {
        $this->assertNull(AppVersion::parse($given));
    }

    public function test_versions_are_compared_by_number_not_by_text(): void
    {
        $this->assertTrue(AppVersion::parse('1.9.0')->isOlderThan(AppVersion::parse('1.10.0')), '1.9 is older than 1.10');
        $this->assertTrue(AppVersion::parse('1.2.9')->isOlderThan(AppVersion::parse('1.3.0')));
        $this->assertTrue(AppVersion::parse('0.99.99')->isOlderThan(AppVersion::parse('1.0.0')));
        $this->assertFalse(AppVersion::parse('1.2.0')->isOlderThan(AppVersion::parse('1.2')), 'the same version is not older');
        $this->assertFalse(AppVersion::parse('2.0.0')->isOlderThan(AppVersion::parse('1.99.99')));
        $this->assertSame(0, AppVersion::parse('1.2')->compareTo(AppVersion::parse('1.2.0')));
        $this->assertGreaterThan(0, AppVersion::parse('1.10.0')->compareTo(AppVersion::parse('1.9.9')));
    }
}
