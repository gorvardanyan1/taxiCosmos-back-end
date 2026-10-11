<?php

namespace App\Services\MobileAuth\Social;

use App\Contracts\SocialTokenVerifier;
use App\Data\SocialIdentity;
use App\Exceptions\MobileAuthException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies an OpenID Connect ID token (Google, Apple): RS256 signature against the provider's
 * published keys, then issuer, audience (our app's client ids) and expiry.
 */
abstract class JwksTokenVerifier implements SocialTokenVerifier
{
    private const LEEWAY_SECONDS = 60;

    abstract protected function jwksUrl(): string;

    /**
     * @return list<string>
     */
    abstract protected function issuers(): array;

    /**
     * @return list<string>
     */
    abstract protected function audiences(): array;

    public function verify(string $token): SocialIdentity
    {
        if ($this->audiences() === []) {
            throw MobileAuthException::providerUnavailable();
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $claims = (array) JWT::decode($token, JWK::parseKeySet($this->keys(), 'RS256'));
        } catch (MobileAuthException $e) {
            throw $e;
        } catch (Throwable) {
            throw MobileAuthException::invalidSocialToken();
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $audience = (array) ($claims['aud'] ?? []);

        if (! in_array($claims['iss'] ?? null, $this->issuers(), true)
            || array_intersect($audience, $this->audiences()) === []
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw MobileAuthException::invalidSocialToken();
        }

        return new SocialIdentity(
            providerUserId: $claims['sub'],
            email: is_string($claims['email'] ?? null) ? $claims['email'] : null,
            // Apple sends the flag as the string "true".
            emailVerified: filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: is_string($claims['name'] ?? null) ? $claims['name'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function keys(): array
    {
        $fetch = function (): array {
            try {
                $response = Http::timeout(5)->acceptJson()->get($this->jwksUrl());
            } catch (ConnectionException) {
                throw MobileAuthException::providerUnavailable();
            }

            $keys = $response->json();

            if (! $response->successful() || ! is_array($keys) || ! isset($keys['keys'])) {
                throw MobileAuthException::providerUnavailable();
            }

            return $keys;
        };

        $cacheKey = 'social-jwks:'.md5($this->jwksUrl());
        $keys = Cache::get($cacheKey);

        if ($keys === null) {
            $keys = $fetch();
            Cache::put($cacheKey, $keys, now()->addHour());
        }

        return $keys;
    }
}
