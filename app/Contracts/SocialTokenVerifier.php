<?php

namespace App\Contracts;

use App\Data\SocialIdentity;
use App\Exceptions\MobileAuthException;

/**
 * Verifies, server-side, the token a native sign-in SDK handed to the app. One implementation per
 * provider (Google, Apple, Facebook).
 */
interface SocialTokenVerifier
{
    /**
     * @throws MobileAuthException 401 when the token is not valid for this app, 503 when the provider
     *                             cannot be reached or is not configured
     */
    public function verify(string $token): SocialIdentity;
}
