<?php

namespace Tests\Unit;

use App\Support\E164PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneMaskTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function numbers(): array
    {
        return [
            'armenian mobile' => ['+37491210443', '+374*****443'],
            'us number' => ['+14155552671', '+141*****671'],
            'long number' => ['+4915123456789', '+491*******789'],
            'short number' => ['+1234567', '******67'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_keeps_the_country_code_and_the_last_digits_only(string $phone, string $masked): void
    {
        $this->assertSame($masked, E164PhoneNumber::mask($phone));
        $this->assertSame(strlen($phone), strlen($masked), 'the length is not changed');
    }
}
