<?php

namespace App\Data;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A freshly issued access/refresh pair. The plain values exist only here, in memory, on their way
 * to the client; the database holds hashes.
 */
final readonly class MobileSession
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $accessTokenExpiresAt,
        public string $refreshToken,
        public User $user,
        public bool $needsPhone,
    ) {}
}
