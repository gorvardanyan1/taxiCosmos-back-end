<?php

namespace Tests\Unit\Support;

use App\Support\BlindIndex;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BlindIndexTest extends TestCase
{
    public function test_hash_is_deterministic_hmac_sha256_of_the_value(): void
    {
        $index = new BlindIndex('unit-test-secret-key-0123456789abcdef');

        $this->assertSame(hash_hmac('sha256', '+15551234567', 'unit-test-secret-key-0123456789abcdef'), $index->hash('+15551234567'));
        $this->assertSame($index->hash('+15551234567'), $index->hash('+15551234567'));
        $this->assertSame(64, strlen($index->hash('+15551234567')));
    }

    public function test_different_values_and_different_keys_give_different_hashes(): void
    {
        $index = new BlindIndex('unit-test-secret-key-0123456789abcdef');

        $this->assertNotSame($index->hash('+15551234567'), $index->hash('+15551234568'));
        $this->assertNotSame($index->hash('+15551234567'), (new BlindIndex('unit-test-other-key-0123456789abcdef'))->hash('+15551234567'));
    }

    public function test_an_empty_or_too_short_key_is_rejected(): void
    {
        foreach (['', 'short-key'] as $key) {
            try {
                new BlindIndex($key);
                $this->fail('Key '.var_export($key, true).' must be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
