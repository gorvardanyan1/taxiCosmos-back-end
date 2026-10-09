<?php

namespace Tests\Unit\Support;

use App\Support\LicenseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LicenseNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'already canonical' => ['AM-DL42', 'AM-DL42'],
            'lower case' => ['am-dl42', 'AM-DL42'],
            'inner and outer spaces' => [' AM DL 42 ', 'AMDL42'],
            'tabs and newlines' => ["AM\tDL\n42", 'AMDL42'],
            'hyphens kept' => ['am-dl-42', 'AM-DL-42'],
            'non-ascii letters upper-cased' => ['ąb12', 'ĄB12'],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, LicenseNumber::normalize($input));
    }
}
