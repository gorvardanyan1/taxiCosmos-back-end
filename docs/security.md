# Security: personal data at rest

Sensitive personal data is encrypted in the database with Laravel's encrypted values (AES-256-CBC with
`APP_KEY`, random IV per value, so the same number encrypts differently each time). Columns that admins
or the mobile login must **search** also get a **blind index**: a deterministic HMAC kept beside the
ciphertext. The code registry is `App\Support\Pii\PiiColumns`; a test fails if a column that looks
sensitive is missing from it.

## What is encrypted

| Column | Blind index | Why searchable / not | Notes |
| --- | --- | --- | --- |
| `users.phone` | `users.phone_hash` (unique) | Mobile OTP login and admin search by exact phone | Stored and hashed in **E.164** (`+37491440221`); input is normalised first, see below |
| `driver_profiles.license_number` | `driver_profiles.license_number_hash` (unique) | The verification workflow looks drivers up by license number | Stored and hashed trimmed, without spaces, upper-case |
| `driver_documents.document_number` | none | Reviewers read it next to the document; nobody searches by it | Hidden on the model and never sent to the browser; document files are covered below |
| `driver_bank_accounts.account_number` | none | Nothing searches by account number, so no index is added | `account_last4` (last 4 characters, plaintext) drives the masked display `•••• 4821`; revealing the full number is an audited action (P4-T2) |

**Columns that will exist later and must be encrypted the same way** (add them to `PiiColumns` when you create them; `PiiColumnsGuardTest` enforces it):

| Column | Task | Blind index |
| --- | --- | --- |
| two-factor secrets and recovery codes | P3-T2 | none |
| stored gateway / provider credentials | P13-T5 | none |

Hashed (not encrypted) by design: `users.password` (bcrypt). Plaintext by design: the blind-index columns,
`driver_bank_accounts.account_last4` and `swift` (the full list is `PiiColumns::plaintextByDesign()`). `otp_codes.phone_hash` is the same
keyed hash of the phone; the code itself is stored only as an HMAC (`code_hash`), the phone never in clear.

Encrypted attributes are `$hidden` on their models, so they never appear in `toArray()`, JSON, Inertia
props or API resources. Never log them.

## Lookups

Use the model scopes, never a decrypt-and-compare:

```php
User::query()->wherePhone('091 440 221')->first();          // normalised to +37491440221, then WHERE phone_hash = ?
DriverProfile::query()->whereLicenseNumber('am-dl 42')->first(); // normalised to AM-DL42, then WHERE license_number_hash = ?
```

Both are an indexed equality lookup on the hash column. `wherePhone()` with something that is not a phone
number matches nothing.

## Phone numbers (E.164)

`App\Support\E164PhoneNumber` (via `propaganistas/laravel-phone`) turns `091 440 221`, `+374 91-440-221` and
`0037491440221` into one value, `+37491440221`, before it is encrypted and hashed, so one person is one
account and one `phone_hash`. Numbers typed without a country code are read in `PHONE_DEFAULT_REGION`
(default `AM`, the fallback for the open launch-country decision). The model only requires that the value
can be parsed; whether it is a real, assigned number is checked in request validation (the `phone` rule,
P3-T3). Setting a phone that cannot be parsed throws `InvalidArgumentException`.

## Keys

| Key | Purpose | Rule |
| --- | --- | --- |
| `APP_KEY` | Encrypts the values | Rotate with `APP_PREVIOUS_KEYS` (below) |
| `BLIND_INDEX_KEY` | HMAC-SHA256 key for the `*_hash` columns | A **separate secret**: at least 32 characters, never equal to `APP_KEY` or an old `APP_KEY` (the app refuses to start the blind index otherwise). Generate with `openssl rand -hex 32` |

Keeping the two apart means a leak of the encryption key does not let an attacker forge or check blind
indexes, and rotating `APP_KEY` never changes a hash.

## Rotating APP_KEY without data loss

1. Generate a new key: `php artisan key:generate --show`.
2. Put the **old** key into `APP_PREVIOUS_KEYS` (comma separated for several) and the new one into `APP_KEY`. Laravel now encrypts with the new key and can still decrypt with the old ones, so nothing breaks while you deploy.
3. Run `php artisan pii:reencrypt --dry-run` (every value can be read, nothing is changed), then `php artisan pii:reencrypt`. It re-encrypts every registered column with the new key and rebuilds each blind index. It is idempotent and safe to run again.
4. Empty `APP_PREVIOUS_KEYS` and redeploy. Anything that still needed the old key would now fail to read: the dry run in step 3 proves nothing does.

Sessions, signed URLs and other values encrypted by Laravel with `APP_KEY` (not registered PII) are also
readable through `APP_PREVIOUS_KEYS`; users may be signed out when the previous keys are removed.

## Rotating BLIND_INDEX_KEY

Change the key, then run `php artisan pii:reencrypt` straight away: it rebuilds every `*_hash` from the
decrypted values. Until it has run, lookups by phone or license number find nothing and OTP login for
existing users fails, so do it in a maintenance window.

## When the normalisation rules change

If `E164PhoneNumber` or `LicenseNumber` change how values are written, existing hashes no longer match new
lookups. Run `php artisan pii:reencrypt` after deploying the change.

## Tests

`PiiColumnsGuardTest` (every registered column is `text`, unreadable in a raw query, readable through the model, hidden from serialisation; no unregistered sensitive-looking column exists), `PiiLookupTest` (indexed hash lookups without decrypting), `ApplicationKeyRotationTest` (rotation with and without the previous key), `BlindIndexKeyTest`.

## Driver and vehicle documents (P2-T3)

- Files are stored on the **private** `driver_documents` disk (`DRIVER_DOCUMENTS_DISK`; set it to an
  S3-compatible disk in staging and production, no code change). Paths are random
  (`drivers/<driver id>/<random>.<ext>`); the client's file name is never used.
- `DriverDocumentUploader` validates by the file's **content** (PDF, JPEG, PNG, WebP; never SVG or HTML) and
  size (`DRIVER_DOCUMENT_MAX_KB`), and removes the file again if saving the row fails.
- Files are only reachable through `GET /admin/drivers/{id}/documents/{docId}/file` with a **signed,
  expiring** link (`DRIVER_DOCUMENT_URL_TTL` minutes, issued fresh on every page load), by a signed-in admin
  with `drivers.view`. The response is `inline`, `nosniff`, `Cache-Control: private, no-store` and carries a
  sandboxing Content-Security-Policy. No route, disk URL or Inertia prop exposes the stored path.
- Reviewing (approve / reject) needs `drivers.verify`, is logged to the activity log (`driver-documents`) with
  the reviewer, the document and the rejection reason, and never logs the document number. All audit entries go through `AuditLogger`, which redacts sensitive values (see [audit-log.md](audit-log.md)).

## Admin web surface hardening (P9-T3)

Everything outside `/api` (the admin pages, the sign-in screens, redirects, error pages, unmatched URLs) is
answered with these headers (`App\Http\Middleware\SecurityHeaders`, global middleware, `config/security.php`):

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | `default-src 'self'`; scripts only from the app (no inline, no eval); styles from the app, inline styles and Google Fonts; fonts from Google Fonts; `img-src` self, data:, blob:, https:; `connect-src 'self'`; `object-src 'none'`; `base-uri`, `form-action` self; **`frame-ancestors 'none'`**. While `npm run dev` runs, the Vite dev server is added. `SECURITY_CSP_REPORT_ONLY=true` sends it as `Content-Security-Policy-Report-Only` to trial changes. |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`, on HTTPS responses only (`SECURITY_HSTS_MAX_AGE`, 0 = off). Not sent over plain HTTP, where browsers ignore it. |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `same-origin` |
| `Permissions-Policy` | accelerometer, camera, geolocation, gyroscope, microphone, payment and usb all denied |

`/horizon` gets all of them except the CSP (Horizon's dashboard needs inline scripts). `/api/v1` responses are JSON and
are left alone. A new external script, font or image host must be added to the policy in `SecurityHeaders` /
`config/security.php` deliberately; the feature tests pin the exact policy.

**Cookies.** The session cookie is `HttpOnly` and `SameSite=Lax` always, and `Secure` whenever `APP_ENV` is `staging` or
`production` (override with `SESSION_SECURE_COOKIE=true|false`; a blank or invalid value falls back to the default).
The `XSRF-TOKEN` cookie follows the same `Secure`/`SameSite` settings but is deliberately not `HttpOnly`: the page's
JavaScript has to read it to send the CSRF header.

**IP allowlist (optional).** `ADMIN_IP_ALLOWLIST` is a comma separated list of IPv4/IPv6 addresses or CIDR ranges
(`203.0.113.7,10.0.0.0/8,2001:db8::/32`). When it is not empty, `/admin`, `/horizon` and the admin sign-in screens
(`/login`, `/forgot-password`, `/reset-password`, `/accept-invitation`, `/two-factor-*`) answer `403` to every other
address, before authentication, so a guest sees `403` and a signed-in admin loses access from outside the list.
`/api/v1`, `/up` and `/` are never restricted. It is **off by default** (empty).

**Reverse proxies.** The client address behind a proxy is only right when the proxy is trusted: set `TRUSTED_PROXIES`
(addresses/CIDRs, or `*` when the proxy is the only thing that can reach the app). Without it every request appears to come
from the proxy, which defeats the allowlist and pools every client into one rate-limit budget. HTTPS detection (HSTS,
`Secure` cookies) depends on it as well.

**Rate limits** are listed in `docs/rate-limits.md`.

**Tests:** `tests/Feature/Security/` (headers, allowlist, cookies, rate-limit responses), `LoginRateLimitTest`, and the
Cypress spec `cypress/e2e/p9-t3-rate-limits-security.cy.ts`.
