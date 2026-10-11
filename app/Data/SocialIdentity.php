<?php

namespace App\Data;

/**
 * What a provider vouches for after its token was verified server-side.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $providerUserId,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {}
}
