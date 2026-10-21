<?php

namespace App\Services\AppConfig;

use App\Enums\AppClient;
use App\Enums\DevicePlatform;
use App\Enums\SocialProvider;
use App\Enums\VehicleClass;
use App\Support\AppVersion;
use App\Support\Locales;

/**
 * What an app asks for when it starts (GET /api/v1/app-config): whether this build may still run, which features are switched on,
 * how to reach support and where the legal pages are. Everything comes from config/.env (`taxikosmos.app_config`), so it is
 * public, identical for everyone and free of secrets: only the values listed here ever leave the server.
 */
final class AppConfigService
{
    /**
     * @return array<string, mixed>
     */
    public function for(AppClient $app, DevicePlatform $platform, ?string $version): array
    {
        return [
            'update' => $this->update($app, $platform, AppVersion::parse($version)),
            'features' => $this->features(),
            'support' => [
                'phone' => $this->text('support.phone'),
                'email' => $this->text('support.email'),
            ],
            'legal' => [
                'terms' => $this->perLocale('legal.terms'),
                'privacy' => $this->perLocale('legal.privacy'),
            ],
            'vehicle_classes' => array_map(
                fn (VehicleClass $class) => ['id' => $class->value, 'name' => $class->label()],
                VehicleClass::cases(),
            ),
        ];
    }

    /**
     * A build with no version, or one that is not a version, is unsupported: an old app must never slip past the gate by
     * leaving the parameter out. A minimum that is not configured (or is not a version) forces nobody.
     *
     * @return array{minimum_version: ?string, latest_version: ?string, force_update: bool, update_available: bool, store_url: ?string}
     */
    private function update(AppClient $app, DevicePlatform $platform, ?AppVersion $version): array
    {
        $minimum = AppVersion::parse($this->text("versions.{$app->value}.{$platform->value}.minimum"));
        $latest = AppVersion::parse($this->text("versions.{$app->value}.{$platform->value}.latest"));

        $forced = $version === null || ($minimum !== null && $version->isOlderThan($minimum));
        $available = $forced || ($latest !== null && $version->isOlderThan($latest));

        return [
            'minimum_version' => $minimum?->__toString(),
            'latest_version' => $latest?->__toString(),
            'force_update' => $forced,
            'update_available' => $available,
            'store_url' => $this->text("store_urls.{$app->value}.{$platform->value}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function features(): array
    {
        $features = [
            'card_payments' => (bool) config('taxikosmos.app_config.features.card_payments'),
            'wallet' => (bool) config('taxikosmos.app_config.features.wallet'),
            'masked_calls' => (bool) config('taxikosmos.app_config.features.masked_calls'),
            'sos' => (bool) config('taxikosmos.app_config.features.sos'),
            'trip_share' => (bool) config('taxikosmos.app_config.features.trip_share'),
        ];

        $features['social_login'] = [];
        foreach (SocialProvider::cases() as $provider) {
            $features['social_login'][$provider->value] = (bool) config("taxikosmos.app_config.features.social_login.{$provider->value}");
        }

        return $features;
    }

    /**
     * @return array<string, ?string> one URL (or null) for every supported language
     */
    private function perLocale(string $key): array
    {
        $urls = [];
        foreach (Locales::supported() as $locale) {
            $urls[$locale] = $this->text("{$key}.{$locale}");
        }

        return $urls;
    }

    private function text(string $key): ?string
    {
        $value = config("taxikosmos.app_config.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
