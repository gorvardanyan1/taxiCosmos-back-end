<?php

namespace Tests\Feature\MobileAuth;

use App\Exceptions\MobileAuthException;
use App\Services\MobileAuth\Social\AppleTokenVerifier;
use App\Services\MobileAuth\Social\FacebookTokenVerifier;
use App\Services\MobileAuth\Social\GoogleTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real verifier classes, with the providers' endpoints faked and tokens signed by a key we
 * generate (so signature, issuer, audience and expiry are all really checked).
 */
class SocialTokenVerifierTest extends TestCase
{
    private const GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const APPLE_JWKS = 'https://appleid.apple.com/auth/keys';

    /** @var array{private: string, jwk: array<string, string>} */
    private array $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->key = $this->makeKey('kid-1');
        config([
            'taxikosmos.mobile_auth.social.google_client_ids' => ['google-client.apps.googleusercontent.com'],
            'taxikosmos.mobile_auth.social.apple_client_ids' => ['com.taxikosmos.rider', 'com.taxikosmos.driver'],
            'taxikosmos.mobile_auth.social.facebook_app_id' => '12345',
            'taxikosmos.mobile_auth.social.facebook_app_secret' => 'fb-secret',
        ]);
    }

    /**
     * @return array{private: string, jwk: array<string, string>}
     */
    private function makeKey(string $kid): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return ['private' => $private, 'jwk' => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function token(array $claims, ?array $key = null, string $kid = 'kid-1'): string
    {
        $claims += ['sub' => 'user-123', 'iat' => time(), 'exp' => time() + 600];

        return JWT::encode($claims, ($key ?? $this->key)['private'], 'RS256', $kid);
    }

    private function jwks(string $url): void
    {
        Http::fake([$url => Http::response(['keys' => [$this->key['jwk']]])]);
    }

    private function googleClaims(array $override = []): array
    {
        return [...['iss' => 'https://accounts.google.com', 'aud' => 'google-client.apps.googleusercontent.com', 'email' => 'ann@example.com', 'email_verified' => true, 'name' => 'Ann'], ...$override];
    }

    private function assertRejected(callable $call, int $status = 401): void
    {
        try {
            $call();
            $this->fail('The token was accepted.');
        } catch (MobileAuthException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    // ---- Google ----

    public function test_a_valid_google_token_gives_the_identity(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $identity = app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims()));

        $this->assertSame('user-123', $identity->providerUserId);
        $this->assertSame('ann@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Ann', $identity->name);
    }

    public function test_google_accepts_the_bare_issuer_form_too(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $this->assertSame('user-123', app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(['iss' => 'accounts.google.com'])))->providerUserId);
    }

    public function test_a_token_for_another_app_is_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(['aud' => 'someone-elses-client']))));
    }

    public function test_the_audience_may_be_a_list_that_includes_ours(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $identity = app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(['aud' => ['other', 'google-client.apps.googleusercontent.com']])));

        $this->assertSame('user-123', $identity->providerUserId);
    }

    public function test_a_wrong_issuer_is_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(['iss' => 'https://evil.example.com']))));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(['exp' => time() - 3600, 'iat' => time() - 7200]))));
    }

    public function test_a_token_signed_by_another_key_is_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);
        $attacker = $this->makeKey('kid-1');

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(), $attacker)));
    }

    public function test_an_unsigned_or_hs256_token_is_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);
        $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=').'.'
            .rtrim(strtr(base64_encode(json_encode($this->googleClaims() + ['sub' => 'x', 'exp' => time() + 600])), '+/', '-_'), '=').'.';
        $hs = JWT::encode($this->googleClaims() + ['sub' => 'x', 'exp' => time() + 600], $this->key['jwk']['n'].'-padding-padding-padding', 'HS256', 'kid-1');

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($none));
        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($hs));
    }

    public function test_garbage_and_missing_subject_are_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify('not-a-jwt'));
        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(['sub' => '']))));
    }

    public function test_an_unknown_key_id_is_refused(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims(), kid: 'rotated-away')));
    }

    public function test_the_provider_keys_are_cached(): void
    {
        $this->jwks(self::GOOGLE_JWKS);

        app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims()));
        app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims()));

        Http::assertSentCount(1);
    }

    public function test_an_unreachable_or_broken_key_endpoint_is_a_503_not_a_401(): void
    {
        Http::fake([self::GOOGLE_JWKS => fn () => throw new ConnectionException('down')]);
        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims())), 503);

        Cache::flush();
        Http::fake([self::GOOGLE_JWKS => Http::response('oops', 500)]);
        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims())), 503);
    }

    public function test_without_configured_client_ids_the_provider_is_unavailable(): void
    {
        config(['taxikosmos.mobile_auth.social.google_client_ids' => []]);
        Http::fake();

        $this->assertRejected(fn () => app(GoogleTokenVerifier::class)->verify($this->token($this->googleClaims())), 503);
        Http::assertNothingSent();
    }

    // ---- Apple ----

    public function test_a_valid_apple_token_for_either_app_works_and_reads_the_string_boolean(): void
    {
        $this->jwks(self::APPLE_JWKS);

        foreach (['com.taxikosmos.rider', 'com.taxikosmos.driver'] as $bundle) {
            $identity = app(AppleTokenVerifier::class)->verify($this->token([
                'iss' => 'https://appleid.apple.com', 'aud' => $bundle, 'sub' => '001234.abcd', 'email' => 'x@privaterelay.appleid.com', 'email_verified' => 'true',
            ]));

            $this->assertSame('001234.abcd', $identity->providerUserId);
            $this->assertTrue($identity->emailVerified);
        }
    }

    public function test_apple_string_false_is_not_verified(): void
    {
        $this->jwks(self::APPLE_JWKS);

        $identity = app(AppleTokenVerifier::class)->verify($this->token(['iss' => 'https://appleid.apple.com', 'aud' => 'com.taxikosmos.rider', 'email' => 'x@y.com', 'email_verified' => 'false']));

        $this->assertFalse($identity->emailVerified);
    }

    public function test_apple_refuses_a_google_token(): void
    {
        $this->jwks(self::APPLE_JWKS);

        $this->assertRejected(fn () => app(AppleTokenVerifier::class)->verify($this->token($this->googleClaims())));
    }

    public function test_apple_without_a_wrong_audience_is_refused(): void
    {
        $this->jwks(self::APPLE_JWKS);

        $this->assertRejected(fn () => app(AppleTokenVerifier::class)->verify($this->token(['iss' => 'https://appleid.apple.com', 'aud' => 'com.other.app'])));
    }

    // ---- Facebook ----

    private function facebookOk(array $debug = [], array $profile = []): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => [...['is_valid' => true, 'app_id' => '12345', 'user_id' => 'fb-77'], ...$debug]]),
            'graph.facebook.com/*/me*' => Http::response([...['id' => 'fb-77', 'name' => 'Fay', 'email' => 'fay@example.com'], ...$profile]),
        ]);
    }

    public function test_a_valid_facebook_token_gives_an_unverified_identity(): void
    {
        $this->facebookOk();

        $identity = app(FacebookTokenVerifier::class)->verify('fb-access-token');

        $this->assertSame('fb-77', $identity->providerUserId);
        $this->assertSame('fay@example.com', $identity->email);
        $this->assertFalse($identity->emailVerified);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'debug_token')
            && $request['input_token'] === 'fb-access-token' && $request['access_token'] === '12345|fb-secret');
    }

    public function test_facebook_tokens_for_another_app_or_invalid_ones_are_refused(): void
    {
        $this->facebookOk(['app_id' => '99999']);
        $this->assertRejected(fn () => app(FacebookTokenVerifier::class)->verify('t'));

        $this->facebookOk(['is_valid' => false]);
        $this->assertRejected(fn () => app(FacebookTokenVerifier::class)->verify('t'));

        $this->facebookOk(profile: ['id' => 'someone-else']);
        $this->assertRejected(fn () => app(FacebookTokenVerifier::class)->verify('t'));

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);
        $this->assertRejected(fn () => app(FacebookTokenVerifier::class)->verify('t'));
    }

    public function test_facebook_down_or_unconfigured_is_a_503(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('down')]);
        $this->assertRejected(fn () => app(FacebookTokenVerifier::class)->verify('t'), 503);

        config(['taxikosmos.mobile_auth.social.facebook_app_secret' => '']);
        Http::fake();
        $this->assertRejected(fn () => app(FacebookTokenVerifier::class)->verify('t'), 503);
        Http::assertNothingSent();
    }
}
