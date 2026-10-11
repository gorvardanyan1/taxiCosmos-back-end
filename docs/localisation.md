# Localisation (English, Russian, Armenian)

The admin panel and the mobile API are available in **English (`en`, default), Russian (`ru`) and
Armenian (`hy`)** (P13-T11). The list is `config('taxikosmos.locales')`.

## One dictionary for server and browser

English text is the key. `lang/ru.json` and `lang/hy.json` hold every translation (plain Laravel JSON
translation files); English needs no file, a missing key shows itself.

| Where | How |
| --- | --- |
| PHP (messages, exceptions, validation) | `__('Rider suspended.')`, `__('… :name', ['name' => …])`, `trans_choice('…|…', $n)` |
| React | `t('Rider suspended.')`, `t('Edit :name', { name })`, `tc(':count result|:count results', n)`, `tn('Cancel :trip?', { trip: <b>…</b> })` for a sentence with elements inside, `tk('Riders')` for a constant declared at module level (translated where it is shown) |
| Laravel's own messages | `lang/{ru,hy}/{validation,auth,passwords,pagination}.php` (from `laravel-lang/lang`, MIT) plus translated field names in `validation.php` → `attributes` |

Placeholders are `:name` everywhere. Plural strings list the forms separated by `|`:
**English and Armenian: 2 forms (one|other); Russian: 3 (one|few|many)**, e.g.
`":count driver|:count drivers"` → `":count водитель|:count водителя|:count водителей"`.
Write a whole sentence per key and let the translation move placeholders where the grammar needs it
(never build a sentence from pieces).

## Which language is used

`App\Http\Middleware\SetLocale` (web and API groups), highest first:

* **Admin panel:** the signed-in admin's saved language (My Account → Language, `users.locale`) → the
  sign-in screens' choice (`?lang=ru`, kept in the session) → the browser's `Accept-Language` → English.
* **Mobile API:** `Accept-Language` (the app sends the language it shows) → the user's saved language → English.
* A URL that matches no route never reaches the web middleware, so its error page uses the browser's language.

Every response carries `Content-Language`. The browser loads only the active dictionary
(`resources/js/lib/i18n.ts`, a lazy chunk of about 15 kB gzipped) before the first render, and
changing language reloads the page. Dates, times, numbers, money and relative times use `Intl` in the
language (`lib/format.ts`); money stays integer minor units and is only formatted when shown.

Emails and notifications to an admin are sent in their language (`User::preferredLocale()`). The
one-time-code text message uses the request's language.

## Adding or changing a string

1. Write the English text in the code (`t()` / `__()`).
2. Add the key to **both** `lang/ru.json` and `lang/hy.json`, with the same `:placeholders` and the right
   number of plural forms.
3. Run `npx vitest run resources/js/test/i18nGuard.test.ts` and `php artisan test --filter=TranslationFilesTest`.

These fail when a string is hardcoded in a component (the guard scans every file under `resources/js`),
when a key is missing in either language, when a placeholder or plural form does not match, or when a
dictionary entry is no longer used. Do not call `t()` at module level (it would freeze English); use
`tk()` there and `t()` at the point of use.

## Adding a language

Add its code to `config/taxikosmos.php` → `locales`, a name to `App\Support\Locales::NAMES` and
`LOCALE_NAMES`/`INTL_LOCALES`/`PLURAL_ORDER` in `resources/js/lib/i18n.ts`, a `lang/xx.json`,
`lang/xx/*.php` (laravel-lang has most), and extend the tests' language lists.

## Not translated (on purpose)

Names typed by people (riders, drivers, zones, addresses), codes and ids, currency codes, brand
names (Stripe, Google, Apple, Facebook) and the audit log's stored text. Enum values the server may
add later fall back to their readable English words until they get an entry in `lib/labels.ts`.
