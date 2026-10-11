<?php

namespace App\Services\MobileAuth\Social;

use App\Contracts\SocialTokenVerifier;
use App\Data\SocialIdentity;
use App\Exceptions\MobileAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Facebook access tokens are opaque: ask Graph who issued the token (debug_token with our app
 * token) and require it to belong to our app, then read the profile with it.
 */
final class FacebookTokenVerifier implements SocialTokenVerifier
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    public function verify(string $token): SocialIdentity
    {
        $appId = (string) config('taxikosmos.mobile_auth.social.facebook_app_id');
        $secret = (string) config('taxikosmos.mobile_auth.social.facebook_app_secret');

        if ($appId === '' || $secret === '') {
            throw MobileAuthException::providerUnavailable();
        }

        try {
            $debug = Http::timeout(5)->acceptJson()->get(self::GRAPH.'/debug_token', ['input_token' => $token, 'access_token' => "{$appId}|{$secret}"]);
            $data = $debug->json('data');

            if (! $debug->successful() || ! is_array($data) || ($data['is_valid'] ?? false) !== true
                || (string) ($data['app_id'] ?? '') !== $appId || ! is_string($data['user_id'] ?? null)) {
                throw MobileAuthException::invalidSocialToken();
            }

            $profile = Http::timeout(5)->acceptJson()->get(self::GRAPH.'/me', ['fields' => 'id,name,email', 'access_token' => $token]);
        } catch (ConnectionException) {
            throw MobileAuthException::providerUnavailable();
        }

        if (! $profile->successful() || $profile->json('id') !== $data['user_id']) {
            throw MobileAuthException::invalidSocialToken();
        }

        // Facebook does not say whether an email is verified, so it is never trusted to link accounts.
        return new SocialIdentity($data['user_id'], $profile->json('email'), false, $profile->json('name'));
    }
}
