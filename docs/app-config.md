# App config (P14-T4)

`GET /api/v1/app-config?app=rider|driver&platform=ios|android&version=1.2.3` is the first call both apps make, before anyone has
signed in. It says whether this build may still run, which features are switched on, how to reach support, where the legal pages are
and what the vehicle classes are called. **Public** (no token), **rate limited** per address (`app-config`, 60 a minute) and
**cacheable**. Everything comes from `config('taxikosmos.app_config')`, i.e. `.env` (MVP scope: change `.env` and deploy; there is no
admin screen and no settings table).

## Request

| Parameter | Rules |
| --- | --- |
| `app` | required: `rider` or `driver` |
| `platform` | required: `ios` or `android` |
| `version` | optional, up to 32 characters. `1.4` or `1.4.2`, digits only. **A missing or malformed version is not an error: the build counts as unsupported** (`force_update: true`), so an old app cannot skip the gate by leaving it out. |

An unknown `app` or `platform` is `422` with the usual `{"message", "errors": {field: [...]}}`.

## Response `200`

```json
{
  "app": "rider",
  "platform": "ios",
  "update": {
    "minimum_version": "1.2.0",
    "latest_version": "1.4.0",
    "force_update": false,
    "update_available": true,
    "store_url": "https://apps.apple.com/app/..."
  },
  "features": {
    "card_payments": false, "wallet": false, "masked_calls": false, "sos": false, "trip_share": false,
    "social_login": { "google": false, "apple": false, "facebook": false }
  },
  "support": { "phone": "+37410000000", "email": "help@example.com" },
  "legal": {
    "terms": { "en": "https://...", "hy": null, "ru": "https://..." },
    "privacy": { "en": "https://...", "hy": null, "ru": null }
  },
  "vehicle_classes": [ { "id": "economy", "name": "Economy" }, { "id": "comfort", "name": "Comfort" }, { "id": "business", "name": "Business" } ]
}
```

- **`update.force_update`**: `true` when the build is older than `minimum_version`, or the version is missing or not a version.
  The app shows the update screen (with `store_url`) and cannot continue. Versions are compared by number (`1.10.0` is newer than
  `1.2.0`; `1.2` means `1.2.0`).
- **`update.update_available`**: `true` when a newer build than this one exists (`latest_version`) or the update is forced. The app
  may show a dismissible hint.
- A minimum (or latest) version that is not set, or is not a version, is `null` and forces nobody. `store_url` and the support and
  legal values are `null` until configured (never an empty string).
- **`features`**: the app hides what is `false`. The MVP is cash only, so all of them start `false`.
- **`vehicle_classes[].name`** is in the language of the request (`Accept-Language`: `en`, `ru`, `hy`; anything else is English).
- Nothing else is ever sent: no keys, passwords or internal settings, and the `version` the app sent is never echoed back.

## Caching

`Cache-Control: public, max-age=<APP_CONFIG_CACHE_SECONDS>` (300), `Vary: Accept-Language` and an `ETag`; a request with a matching
`If-None-Match` gets `304`. A change of `.env` (after a deploy) changes the answer and so the ETag.

## Configuration

All in [.env.example](../.env.example), under "App config": `APP_{RIDER|DRIVER}_{IOS|ANDROID}_{MIN|LATEST}_VERSION`,
`APP_{RIDER|DRIVER}_{IOS|ANDROID}_STORE_URL`, `APP_FEATURE_*`, `SUPPORT_PHONE`, `SUPPORT_EMAIL`, `LEGAL_{TERMS|PRIVACY}_URL_{EN|RU|HY}`,
`APP_CONFIG_CACHE_SECONDS`, `RATE_LIMIT_APP_CONFIG`. To force everybody off an old build: raise the `*_MIN_VERSION` and deploy.

## Not done here

- The admin Settings page that edits these values, and the settings table behind it (P13-T5), were cut from the MVP: the values live in
  `.env`. When P13-T5 exists, `AppConfigService` is the one place that reads them.
