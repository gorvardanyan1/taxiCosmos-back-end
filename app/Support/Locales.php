<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The languages the admin panel and the apps are offered in (config taxikosmos.locales: en, ru, hy)
 * and how a request picks one. English is the default and the fallback for missing strings.
 */
final class Locales
{
    /** Each language by its own name (shown in language pickers; never translated). */
    public const NAMES = ['en' => 'English', 'ru' => 'Русский', 'hy' => 'Հայերեն'];

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_values(config('taxikosmos.locales'));
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::supported(), true);
    }

    /**
     * English unless APP_FALLBACK_LOCALE says otherwise. Not config('app.locale'): App::setLocale()
     * overwrites that value for the rest of the process, so it would leak into the next request
     * wherever a worker handles more than one (Octane, queue workers, tests).
     */
    public static function default(): string
    {
        return (string) config('app.fallback_locale');
    }

    /**
     * Best match from the Accept-Language header ("ru-RU,ru;q=0.9,en;q=0.8" → ru), or null.
     */
    public static function fromHeader(Request $request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok(str_replace('-', '_', $language), '_'));
            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }

    public static function forUser(?User $user): ?string
    {
        return $user !== null && self::isSupported($user->locale) ? $user->locale : null;
    }
}
