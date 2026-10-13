<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * P9-T3: TRUSTED_PROXIES decides whether X-Forwarded-For / -Proto are believed, which the IP allowlist, the per-IP rate
 * limits, HSTS and Secure cookies all depend on. It is read from the environment when the application is built
 * (bootstrap/app.php), so each case boots the real application in its own process.
 */
class TrustedProxiesTest extends TestCase
{
    private const PROXY = '198.51.100.9';

    private const CLIENT = '203.0.113.7';

    /**
     * @param  array<string, string>  $env
     * @return array{status: int, hsts: ?string}
     */
    private function requestThroughProxy(array $env, string $forwardedFor = self::CLIENT, ?string $proto = null): array
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/request-status.php')], base_path(), [
            'ADMIN_IP_ALLOWLIST' => self::CLIENT,
            'REQ_PATH' => '/login',
            'REQ_REMOTE_ADDR' => self::PROXY,
            'REQ_FORWARDED_FOR' => $forwardedFor,
            'REQ_FORWARDED_PROTO' => $proto ?? '',
            ...$env,
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_without_trusted_proxies_the_forwarded_address_is_ignored(): void
    {
        foreach (['', ' '] as $unset) {
            $this->assertSame(403, $this->requestThroughProxy(['TRUSTED_PROXIES' => $unset])['status'], "TRUSTED_PROXIES='{$unset}'");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listsContainingTheProxy(): array
    {
        return [
            'the proxy address' => [self::PROXY],
            'a CIDR range' => ['198.51.100.0/24'],
            'a comma separated list with spaces and blanks' => ['10.0.0.0/8, '.self::PROXY.',, 192.0.2.1'],
            'everything' => ['*'],
        ];
    }

    #[DataProvider('listsContainingTheProxy')]
    public function test_a_listed_proxy_is_believed_about_the_client_address(string $trusted): void
    {
        $this->assertSame(200, $this->requestThroughProxy(['TRUSTED_PROXIES' => $trusted])['status']);
    }

    public function test_the_forwarded_address_is_still_judged_by_the_allowlist(): void
    {
        $this->assertSame(403, $this->requestThroughProxy(['TRUSTED_PROXIES' => self::PROXY], forwardedFor: '192.0.2.55')['status']);
    }

    public function test_a_proxy_that_is_not_listed_is_not_believed(): void
    {
        $this->assertSame(403, $this->requestThroughProxy(['TRUSTED_PROXIES' => '192.0.2.1,10.0.0.0/8'])['status']);
    }

    public function test_https_behind_a_trusted_proxy_turns_hsts_on_and_an_untrusted_one_cannot_fake_it(): void
    {
        $trusted = $this->requestThroughProxy(['TRUSTED_PROXIES' => self::PROXY], proto: 'https');
        $this->assertSame('max-age=31536000; includeSubDomains', $trusted['hsts']);

        // Same header from a proxy that is not trusted (the allowlist is off here so the page is reached at all).
        $untrusted = $this->requestThroughProxy(['TRUSTED_PROXIES' => '', 'ADMIN_IP_ALLOWLIST' => ''], proto: 'https');
        $this->assertSame(200, $untrusted['status']);
        $this->assertNull($untrusted['hsts']);
    }
}
