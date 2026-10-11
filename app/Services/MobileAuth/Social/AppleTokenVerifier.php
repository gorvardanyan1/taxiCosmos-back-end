<?php

namespace App\Services\MobileAuth\Social;

final class AppleTokenVerifier extends JwksTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function issuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    protected function audiences(): array
    {
        return config('taxikosmos.mobile_auth.social.apple_client_ids');
    }
}
