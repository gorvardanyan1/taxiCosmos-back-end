<?php

namespace Tests\Unit\Support;

use App\Support\E164PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class E164PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function equivalentWritings(): array
    {
        return [
            'already E.164' => ['+37491440221', '+37491440221'],
            'national with leading zero' => ['091 440 221', '+37491440221'],
            'national without zero' => ['91440221', '+37491440221'],
            'international with separators' => ['+374 91-440-221', '+37491440221'],
            'international 00 prefix' => ['0037491440221', '+37491440221'],
            'surrounding whitespace' => ["  +37491440221\n", '+37491440221'],
            'brackets and dots' => ['+374 (91) 440.221', '+37491440221'],
        ];
    }

    #[DataProvider('equivalentWritings')]
    public function test_every_way_of_writing_a_number_gives_one_e164_value(string $input, string $expected): void
    {
        $this->assertSame($expected, E164PhoneNumber::normalize($input));
    }

    public function test_numbers_from_other_countries_keep_their_own_country_code(): void
    {
        $this->assertSame('+14155552671', E164PhoneNumber::normalize('+1 (415) 555-2671'));
        $this->assertSame('+442071838750', E164PhoneNumber::normalize('+44 20 7183 8750'));
        $this->assertSame('+995322123456', E164PhoneNumber::normalize('00995322123456'));
    }

    public function test_a_number_without_a_country_code_is_read_in_the_configured_region(): void
    {
        config(['taxikosmos.phone.default_region' => 'GE']);
        $this->assertSame('+995599123456', E164PhoneNumber::normalize('599 123 456'));

        config(['taxikosmos.phone.default_region' => 'AM']);
        $this->assertSame('+37491440221', E164PhoneNumber::normalize('091 440 221'));
    }

    public function test_the_launch_region_default_is_armenia(): void
    {
        $this->assertSame('AM', (include base_path('config/taxikosmos.php'))['phone']['default_region']);
    }

    public function test_it_is_idempotent(): void
    {
        $once = E164PhoneNumber::normalize('091 440 221');

        $this->assertSame($once, E164PhoneNumber::normalize($once));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPhoneNumbers(): array
    {
        return ['letters' => ['abc'], 'empty' => [''], 'spaces only' => ['   '], 'symbols' => ['--']];
    }

    #[DataProvider('notPhoneNumbers')]
    public function test_values_that_are_not_phone_numbers_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        E164PhoneNumber::normalize($input);
    }

    public function test_try_normalize_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(E164PhoneNumber::tryNormalize('not a phone'));
        $this->assertSame('+37491440221', E164PhoneNumber::tryNormalize('091 440 221'));
    }

    public function test_a_number_that_parses_but_is_not_assigned_is_still_accepted_validity_is_checked_at_input(): void
    {
        // 555 numbers are not assigned in the US; the model layer only needs a parseable number.
        $this->assertSame('+15551230001', E164PhoneNumber::normalize('+1 555 123 0001'));
    }
}
