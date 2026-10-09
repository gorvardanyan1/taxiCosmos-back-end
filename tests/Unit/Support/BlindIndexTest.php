<?php

namespace Tests\Unit\Support;

use App\Support\BlindIndex;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BlindIndexTest extends TestCase
{
    public function test_hash_is_deterministic_hmac_sha256_of_the_value(): void
    {
        $index = new BlindIndex('secret-key');

        $this->assertSame(hash_hmac('sha256', '+15551234567', 'secret-key'), $index->hash('+15551234567'));
        $this->assertSame($index->hash('+15551234567'), $index->hash('+15551234567'));
        $this->assertSame(64, strlen($index->hash('+15551234567')));
    }

    public function test_different_values_and_different_keys_give_different_hashes(): void
    {
        $index = new BlindIndex('secret-key');

        $this->assertNotSame($index->hash('+15551234567'), $index->hash('+15551234568'));
        $this->assertNotSame($index->hash('+15551234567'), (new BlindIndex('other-key'))->hash('+15551234567'));
    }

    public function test_an_empty_key_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        new BlindIndex('');
    }
}
