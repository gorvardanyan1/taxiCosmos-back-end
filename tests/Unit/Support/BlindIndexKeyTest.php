<?php

namespace Tests\Unit\Support;

use App\Support\BlindIndex;
use RuntimeException;
use Tests\TestCase;

class BlindIndexKeyTest extends TestCase
{
    private function appKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public function test_a_properly_separate_key_is_accepted_and_used_for_the_hmac(): void
    {
        config(['app.key' => $this->appKey(), 'app.previous_keys' => [], 'app.blind_index_key' => str_repeat('k', 40)]);

        $this->assertSame(hash_hmac('sha256', '+37491440221', str_repeat('k', 40)), BlindIndex::fromConfig()->hash('+37491440221'));
    }

    public function test_the_blind_index_key_may_not_be_the_app_key_in_any_spelling(): void
    {
        $raw = random_bytes(32);
        foreach ([['base64:'.base64_encode($raw), 'base64:'.base64_encode($raw)], ['base64:'.base64_encode($raw), $raw]] as [$app, $blind]) {
            config(['app.key' => $app, 'app.previous_keys' => [], 'app.blind_index_key' => str_pad($blind, 32, 'x')]);
            if ($blind === $raw) {
                config(['app.blind_index_key' => $raw]);
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('different secret from APP_KEY');
            BlindIndex::fromConfig();
        }
    }

    public function test_the_blind_index_key_may_not_be_a_previous_app_key(): void
    {
        $old = $this->appKey();
        config(['app.key' => $this->appKey(), 'app.previous_keys' => [$old], 'app.blind_index_key' => $old]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different secret from APP_KEY');

        BlindIndex::fromConfig();
    }

    public function test_a_missing_or_short_key_is_refused(): void
    {
        foreach ([null, '', 'too-short-key', str_repeat('a', 31)] as $key) {
            config(['app.key' => $this->appKey(), 'app.previous_keys' => [], 'app.blind_index_key' => $key]);

            try {
                BlindIndex::fromConfig();
                $this->fail('A blind index key of '.var_export($key, true).' must be refused.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/BLIND_INDEX_KEY (is not configured|must be at least 32)/', $e->getMessage());
            }
        }

        config(['app.blind_index_key' => str_repeat('a', 32)]);
        $this->assertInstanceOf(BlindIndex::class, BlindIndex::fromConfig(), '32 characters is the minimum.');
    }

    public function test_the_key_in_env_example_is_documented_as_a_separate_secret(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^BLIND_INDEX_KEY=$/m', $env);
        $this->assertStringContainsString('secret from APP_KEY', $env);
        $this->assertStringContainsString('openssl rand -hex 32', $env);
        $this->assertMatchesRegularExpression('/^APP_PREVIOUS_KEYS=$/m', $env);
        $this->assertStringContainsString('docs/security.md', $env);
    }
}
