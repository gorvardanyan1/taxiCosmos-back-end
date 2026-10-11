<?php

namespace Tests\Feature\Localisation;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The Russian and Armenian dictionaries (lang/ru.json, lang/hy.json) hold every string the server
 * and the admin UI show. These tests fail when a new string is added without both translations, when a
 * translation drops or invents a :placeholder, or when plural forms do not match the language.
 */
class TranslationFilesTest extends TestCase
{
    private const LOCALES = ['ru', 'hy'];

    /**
     * @return array<string, string>
     */
    private function dictionary(string $locale): array
    {
        return json_decode(file_get_contents(base_path("lang/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every literal passed to __(), trans() or trans_choice() under app/.
     *
     * @return array<string, string> key => "file:line"
     */
    private function serverKeys(): array
    {
        $keys = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

        foreach ($files as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }

            $source = file_get_contents((string) $file);
            preg_match_all('/\b(?:__|trans_choice|trans)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as [$literal, $offset]) {
                $key = stripcslashes(substr($literal, 1, -1));
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;

                // Keys like "auth.throttle" belong to the PHP group files, not the JSON dictionaries.
                if (! preg_match('/^[a-z_]+\.[a-z_.]+$/', $key)) {
                    $keys[$key] = str_replace(base_path().'/', '', (string) $file).":{$line}";
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_]\w*)/', $text, $m);
        $names = array_map('strtolower', array_unique($m[1]));
        sort($names);

        return $names;
    }

    public function test_both_dictionaries_have_exactly_the_same_keys(): void
    {
        $this->assertSame(array_keys($this->dictionary('ru')), array_keys($this->dictionary('hy')));
    }

    public function test_every_string_the_server_translates_exists_in_both_dictionaries(): void
    {
        foreach (self::LOCALES as $locale) {
            $missing = array_diff_key($this->serverKeys(), $this->dictionary($locale));

            $this->assertSame([], $missing, "Missing {$locale} translations (key => where it is used)");
        }
    }

    public function test_no_translation_is_empty_or_just_the_english_text(): void
    {
        // Brand and product names are the same in every language.
        $same = ['Apple', 'Facebook', 'Google', 'Stripe', 'Authorize.Net'];

        foreach (self::LOCALES as $locale) {
            foreach ($this->dictionary($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "{$locale}: \"{$key}\" is empty");

                if (! in_array($key, $same, true)) {
                    $this->assertNotSame($key, $value, "{$locale}: \"{$key}\" is not translated");
                }
            }
        }
    }

    public function test_translations_are_written_in_the_right_script(): void
    {
        foreach ($this->dictionary('ru') as $key => $value) {
            if (preg_match('/\p{L}{3,}/u', $value) && ! in_array($key, ['Apple', 'Facebook', 'Google', 'Stripe', 'Authorize.Net'], true)) {
                $this->assertMatchesRegularExpression('/\p{Cyrillic}/u', $value, "ru: \"{$key}\"");
            }
        }

        foreach ($this->dictionary('hy') as $key => $value) {
            if (preg_match('/\p{L}{3,}/u', $value) && ! in_array($key, ['Apple', 'Facebook', 'Google', 'Stripe', 'Authorize.Net'], true)) {
                $this->assertMatchesRegularExpression('/\p{Armenian}/u', $value, "hy: \"{$key}\"");
            }
        }
    }

    public function test_every_translation_keeps_the_same_placeholders_as_the_english_text(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->dictionary($locale) as $key => $value) {
                $this->assertSame($this->placeholders($key), $this->placeholders($value), "{$locale}: placeholders differ in \"{$key}\"");
            }
        }
    }

    public function test_plural_strings_have_the_forms_the_language_needs(): void
    {
        $forms = ['ru' => 3, 'hy' => 2];

        foreach (self::LOCALES as $locale) {
            foreach ($this->dictionary($locale) as $key => $value) {
                if (! str_contains($key, '|')) {
                    $this->assertStringNotContainsString('|', $value, "{$locale}: \"{$key}\" is not a plural");

                    continue;
                }

                $this->assertCount($forms[$locale], explode('|', $value), "{$locale}: wrong number of plural forms in \"{$key}\"");
            }
        }
    }

    public function test_the_laravel_group_files_are_complete_in_every_language(): void
    {
        foreach (['validation', 'auth', 'passwords', 'pagination'] as $file) {
            $english = require base_path("lang/en/{$file}.php");

            foreach (self::LOCALES as $locale) {
                $translated = require base_path("lang/{$locale}/{$file}.php");
                $flatten = fn (array $a, string $p = '') => $this->flatten($a, $p);

                $missing = array_diff(array_keys($flatten($english)), array_keys($flatten($translated)));
                // validation.custom is an empty extension point in the stock English file.
                $missing = array_values(array_filter($missing, fn ($k) => ! str_starts_with($k, 'custom.')));

                $this->assertSame([], $missing, "lang/{$locale}/{$file}.php is missing keys");
            }
        }
    }

    public function test_the_validation_field_names_used_by_the_forms_are_translated(): void
    {
        foreach (self::LOCALES as $locale) {
            $attributes = (require base_path("lang/{$locale}/validation.php"))['attributes'];

            foreach (['name', 'email', 'phone', 'locale', 'reason', 'code', 'timezone', 'currency', 'priority', 'polygon', 'password', 'amount'] as $field) {
                $this->assertArrayHasKey($field, $attributes, "{$locale}: attribute \"{$field}\"");
            }
        }
    }

    public function test_the_supported_languages_are_exactly_the_dictionaries_plus_english(): void
    {
        $this->assertEqualsCanonicalizing(['en', ...self::LOCALES], config('taxikosmos.locales'));
        $this->assertFileExists(base_path('lang/en/validation.php'));
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, string>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            is_array($value) ? $out += $this->flatten($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = (string) $value;
        }

        return $out;
    }
}
