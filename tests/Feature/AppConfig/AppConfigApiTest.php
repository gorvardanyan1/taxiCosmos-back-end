<?php

namespace Tests\Feature\AppConfig;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P14-T4: GET /api/v1/app-config, the public start-up call of both apps: the update gate, feature flags, support contacts, legal
 * links and vehicle classes, all from config. No database is involved.
 */
class AppConfigApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'taxikosmos.app_config.cache_seconds' => 300,
            'taxikosmos.app_config.versions' => [
                'rider' => [
                    'ios' => ['minimum' => '1.2.0', 'latest' => '1.4.0'],
                    'android' => ['minimum' => '1.0.0', 'latest' => '1.3.0'],
                ],
                'driver' => [
                    'ios' => ['minimum' => '2.0.0', 'latest' => '2.1.0'],
                    'android' => ['minimum' => '', 'latest' => null],
                ],
            ],
            'taxikosmos.app_config.store_urls' => [
                'rider' => ['ios' => 'https://apps.apple.com/app/rider', 'android' => 'https://play.google.com/store/apps/details?id=com.taxikosmos.rider'],
                'driver' => ['ios' => null, 'android' => ''],
            ],
        ]);
    }

    private function get_(string $query): TestResponse
    {
        return $this->getJson('/api/v1/app-config?'.$query);
    }

    private function config_(string $app, string $platform, ?string $version): TestResponse
    {
        return $this->get_(http_build_query(array_filter(['app' => $app, 'platform' => $platform, 'version' => $version], fn ($v) => $v !== null)));
    }

    // ---- the contract ----

    public function test_it_answers_with_the_documented_contract_and_nothing_else(): void
    {
        config([
            'taxikosmos.app_config.features' => [
                'card_payments' => true, 'wallet' => false, 'masked_calls' => false, 'sos' => true, 'trip_share' => false,
                'social_login' => ['google' => true, 'apple' => false, 'facebook' => false],
            ],
            'taxikosmos.app_config.support' => ['phone' => '+37410000000', 'email' => 'help@taxikosmos.test'],
            'taxikosmos.app_config.legal' => [
                'terms' => ['en' => 'https://taxikosmos.test/terms', 'ru' => 'https://taxikosmos.test/ru/terms', 'hy' => null],
                'privacy' => ['en' => 'https://taxikosmos.test/privacy', 'ru' => null, 'hy' => null],
            ],
        ]);

        $this->config_('rider', 'ios', '1.3.0')->assertOk()->assertExactJson([
            'app' => 'rider',
            'platform' => 'ios',
            'update' => [
                'minimum_version' => '1.2.0',
                'latest_version' => '1.4.0',
                'force_update' => false,
                'update_available' => true,
                'store_url' => 'https://apps.apple.com/app/rider',
            ],
            'features' => [
                'card_payments' => true, 'wallet' => false, 'masked_calls' => false, 'sos' => true, 'trip_share' => false,
                'social_login' => ['google' => true, 'apple' => false, 'facebook' => false],
            ],
            'support' => ['phone' => '+37410000000', 'email' => 'help@taxikosmos.test'],
            'legal' => [
                'terms' => ['en' => 'https://taxikosmos.test/terms', 'hy' => null, 'ru' => 'https://taxikosmos.test/ru/terms'],
                'privacy' => ['en' => 'https://taxikosmos.test/privacy', 'hy' => null, 'ru' => null],
            ],
            'vehicle_classes' => [
                ['id' => 'economy', 'name' => 'Economy'],
                ['id' => 'comfort', 'name' => 'Comfort'],
                ['id' => 'business', 'name' => 'Business'],
            ],
        ]);
    }

    public function test_the_mvp_defaults_keep_every_cash_unfriendly_feature_off(): void
    {
        $defaults = require base_path('config/taxikosmos.php');

        foreach (['card_payments', 'wallet', 'masked_calls', 'sos', 'trip_share'] as $flag) {
            $this->assertFalse($defaults['app_config']['features'][$flag], $flag);
        }
        $this->assertSame([false, false, false], array_values($defaults['app_config']['features']['social_login']));
    }

    // ---- the update gate ----

    /**
     * @return array<string, array{?string, bool}> the app's version, whether it must update (minimum 1.2.0)
     */
    public static function riderIosVersions(): array
    {
        return [
            'older patch' => ['1.1.9', true],
            'exactly the minimum' => ['1.2.0', false],
            'the minimum written with two parts' => ['1.2', false],
            'newer patch' => ['1.2.1', false],
            'compared by number, not text (1.10 is newer than 1.2)' => ['1.10.0', false],
            'much older' => ['0.9.0', true],
            'a major ahead' => ['3.0.0', false],
            'no version at all' => [null, true],
            'an empty version' => ['', true],
            'garbage' => ['not-a-version', true],
            'a build suffix' => ['1.5.0-beta', true],
            'too many parts' => ['1.5.0.1', true],
            'a v prefix' => ['v1.5.0', true],
        ];
    }

    #[DataProvider('riderIosVersions')]
    public function test_a_build_below_the_minimum_must_update_and_a_missing_or_malformed_version_counts_as_below(?string $version, bool $mustUpdate): void
    {
        $this->config_('rider', 'ios', $version)->assertOk()
            ->assertJsonPath('update.force_update', $mustUpdate)
            ->assertJsonPath('update.minimum_version', '1.2.0');
    }

    public function test_an_update_is_offered_while_a_newer_build_exists_and_not_once_it_is_installed(): void
    {
        $this->config_('rider', 'ios', '1.3.0')->assertJsonPath('update.update_available', true)->assertJsonPath('update.force_update', false);
        $this->config_('rider', 'ios', '1.4.0')->assertJsonPath('update.update_available', false);
        $this->config_('rider', 'ios', '1.4.1')->assertJsonPath('update.update_available', false);
        $this->config_('rider', 'ios', '0.1.0')->assertJsonPath('update.update_available', true);
        $this->config_('rider', 'ios', null)->assertJsonPath('update.update_available', true);
    }

    public function test_each_app_and_platform_has_its_own_minimum_latest_and_store_link(): void
    {
        $this->config_('rider', 'android', '1.0.0')->assertJsonPath('update', [
            'minimum_version' => '1.0.0', 'latest_version' => '1.3.0', 'force_update' => false, 'update_available' => true,
            'store_url' => 'https://play.google.com/store/apps/details?id=com.taxikosmos.rider',
        ]);
        $this->config_('driver', 'ios', '1.9.9')->assertJsonPath('update.force_update', true)->assertJsonPath('update.minimum_version', '2.0.0');
        // Same version, other platform: not forced there.
        $this->config_('rider', 'android', '1.1.0')->assertJsonPath('update.force_update', false);
        $this->config_('rider', 'ios', '1.1.0')->assertJsonPath('update.force_update', true);
    }

    public function test_a_minimum_that_is_not_configured_forces_nobody_but_a_missing_version_is_still_unsupported(): void
    {
        $this->config_('driver', 'android', '0.0.1')->assertOk()
            ->assertJsonPath('update.force_update', false)
            ->assertJsonPath('update.minimum_version', null)
            ->assertJsonPath('update.latest_version', null)
            ->assertJsonPath('update.update_available', false)
            ->assertJsonPath('update.store_url', null);

        $this->config_('driver', 'android', null)->assertJsonPath('update.force_update', true);
    }

    public function test_a_minimum_that_is_not_a_version_forces_nobody(): void
    {
        config(['taxikosmos.app_config.versions.rider.ios.minimum' => 'soon']);

        $this->config_('rider', 'ios', '0.0.1')->assertJsonPath('update.force_update', false)->assertJsonPath('update.minimum_version', null);
    }

    public function test_a_store_link_that_is_not_set_is_null_not_an_empty_string(): void
    {
        $this->config_('driver', 'ios', '2.0.0')->assertJsonPath('update.store_url', null);
        $this->config_('driver', 'android', '2.0.0')->assertJsonPath('update.store_url', null);
    }

    // ---- who may ask, and what they may ask ----

    public function test_it_needs_no_token_and_a_bad_one_changes_nothing(): void
    {
        $this->config_('rider', 'ios', '1.2.0')->assertOk();
        $this->getJson('/api/v1/app-config?app=rider&platform=ios&version=1.2.0', ['Authorization' => 'Bearer 1|not-a-real-token'])->assertOk();
    }

    /**
     * @return array<string, array{string, string}> query, the field with the error
     */
    public static function badQueries(): array
    {
        return [
            'no app' => ['platform=ios&version=1.0.0', 'app'],
            'no platform' => ['app=rider&version=1.0.0', 'platform'],
            'an app that does not exist' => ['app=admin&platform=ios&version=1.0.0', 'app'],
            'a platform that does not exist' => ['app=rider&platform=windows&version=1.0.0', 'platform'],
            'an app that is not a string' => ['app[]=rider&platform=ios', 'app'],
            'a version that is far too long' => ['app=rider&platform=ios&version='.'1.2.3.4.5.6.7.8.9.10.11.12.13.14.15', 'version'],
        ];
    }

    #[DataProvider('badQueries')]
    public function test_an_unknown_app_or_platform_is_a_validation_error_on_that_field(string $query, string $field): void
    {
        $this->get_($query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_the_route_is_public_and_rate_limited_and_nothing_else(): void
    {
        $route = app('router')->getRoutes()->match(request()->create('/api/v1/app-config', 'GET'));

        $this->assertSame(['api', 'throttle:app-config'], array_values($route->gatherMiddleware()));
    }

    public function test_it_is_rate_limited_per_address_with_a_retry_delay(): void
    {
        config(['security.rate_limits.app_config' => 3]);
        $query = 'app=rider&platform=ios&version=1.2.0';

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson("/api/v1/app-config?{$query}")->assertOk();
        }

        $limited = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson("/api/v1/app-config?{$query}");
        $limited->assertStatus(429);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));

        // Another address has its own budget.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->getJson("/api/v1/app-config?{$query}")->assertOk();
    }

    // ---- cache ----

    public function test_it_is_publicly_cacheable_for_the_configured_time_and_varies_by_language(): void
    {
        config(['taxikosmos.app_config.cache_seconds' => 120]);

        $response = $this->config_('rider', 'ios', '1.2.0')->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=120', $cacheControl);
        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_a_client_that_already_has_the_answer_gets_304_and_a_changed_answer_gets_a_new_etag(): void
    {
        $first = $this->config_('rider', 'ios', '1.2.0')->assertOk();
        $etag = $first->headers->get('ETag');

        $again = $this->getJson('/api/v1/app-config?app=rider&platform=ios&version=1.2.0', ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());

        // The change of a setting is a different answer: the old tag no longer matches.
        config(['taxikosmos.app_config.versions.rider.ios.minimum' => '1.3.0']);
        $changed = $this->getJson('/api/v1/app-config?app=rider&platform=ios&version=1.2.0', ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $changed->assertJsonPath('update.force_update', true);
    }

    public function test_the_etag_differs_for_another_version_and_another_language(): void
    {
        $en = $this->config_('rider', 'ios', '1.2.0')->headers->get('ETag');
        $older = $this->config_('rider', 'ios', '1.1.0')->headers->get('ETag');
        $ru = $this->withHeaders(['Accept-Language' => 'ru'])->getJson('/api/v1/app-config?app=rider&platform=ios&version=1.2.0')->headers->get('ETag');

        $this->assertNotSame($en, $older);
        $this->assertNotSame($en, $ru);
    }

    // ---- language ----

    public function test_vehicle_class_names_follow_the_language_of_the_request(): void
    {
        $names = function (?string $language): array {
            $response = $language === null
                ? $this->config_('rider', 'ios', '1.2.0')
                : $this->withHeaders(['Accept-Language' => $language])->getJson('/api/v1/app-config?app=rider&platform=ios&version=1.2.0');

            return array_column($response->assertOk()->json('vehicle_classes'), 'name');
        };

        $this->assertSame(['Economy', 'Comfort', 'Business'], $names(null));
        $this->assertSame(['Эконом', 'Комфорт', 'Бизнес'], $names('ru'));
        $this->assertSame(['Էկոնոմ', 'Կոմֆորտ', 'Բիզնես'], $names('hy'));
        $this->assertSame(['Economy', 'Comfort', 'Business'], $names('fr'), 'a language we do not offer falls back to English');
    }

    // ---- no secrets ----

    public function test_nothing_but_the_documented_fields_ever_leaves_the_server(): void
    {
        config([
            'app.key' => 'base64:SECRET-APP-KEY',
            'services.maps.google.key' => 'SECRET-MAPS-KEY',
            'services.maps.2gis.key' => 'SECRET-2GIS-KEY',
            'services.maps.osm.user_agent' => 'SECRET-UA',
            'database.connections.pgsql.password' => 'SECRET-DB-PASSWORD',
            'taxikosmos.app_config.cache_seconds' => 300,
        ]);

        $body = $this->config_('rider', 'ios', '1.2.0')->assertOk();
        $json = $body->json();

        $this->assertSame(['app', 'features', 'legal', 'platform', 'support', 'update', 'vehicle_classes'], collect($json)->keys()->sort()->values()->all());
        $this->assertSame(['force_update', 'latest_version', 'minimum_version', 'store_url', 'update_available'], collect($json['update'])->keys()->sort()->values()->all());
        $this->assertStringNotContainsString('SECRET', $body->getContent());
        $this->assertStringNotContainsString('SECRET', json_encode($body->headers->all()));
    }

    public function test_the_version_the_app_sent_is_never_echoed_back(): void
    {
        $body = $this->get_('app=rider&platform=ios&version='.urlencode('<script>alert(1)</script>'))->assertOk()->getContent();

        $this->assertStringNotContainsString('script', $body);
    }
}
