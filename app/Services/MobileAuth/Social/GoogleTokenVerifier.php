<?php

namespace App\Services\MobileAuth\Social;

final class GoogleTokenVerifier extends JwksTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function issuers(): array
    {
        return ['https://accounts.google.com', 'accounts.google.com'];
    }

    protected function audiences(): array
    {
        return config('taxikosmos.mobile_auth.social.google_client_ids');
    }
}
