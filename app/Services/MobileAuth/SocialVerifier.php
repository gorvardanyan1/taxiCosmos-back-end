<?php

namespace App\Services\MobileAuth;

use App\Contracts\SocialTokenVerifier;
use App\Data\SocialIdentity;
use App\Enums\SocialProvider;
use App\Services\MobileAuth\Social\AppleTokenVerifier;
use App\Services\MobileAuth\Social\FacebookTokenVerifier;
use App\Services\MobileAuth\Social\GoogleTokenVerifier;

/**
 * Picks the verifier for a provider. The verifiers are resolved from the container, so tests can
 * replace one with a fake.
 */
final class SocialVerifier
{
    public function verify(SocialProvider $provider, string $token): SocialIdentity
    {
        return $this->for($provider)->verify($token);
    }

    public function for(SocialProvider $provider): SocialTokenVerifier
    {
        return app(match ($provider) {
            SocialProvider::Google => GoogleTokenVerifier::class,
            SocialProvider::Apple => AppleTokenVerifier::class,
            SocialProvider::Facebook => FacebookTokenVerifier::class,
        });
    }
}
