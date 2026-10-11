# Mobile authentication (`/api/v1/auth`)

For the rider and driver apps (P3-T3). Sanctum **bearer tokens only**: no sessions, no cookies. A
browser (admin) session is not accepted on `/api/v1` (`sanctum.guard` is empty), and an account with
admin access is refused a mobile token (403 at sign-in, 401 on refresh, 403 on a token it already held).

## Endpoints

| Method + path | Auth | Purpose |
| --- | --- | --- |
| `POST /auth/otp/request` `{phone, app}` | none | Send a 6-digit code by SMS. Always 202, whether or not the phone has an account. |
| `POST /auth/otp/verify` `{phone, code, app, device_id, locale?}` | none | Check the code; sign in, or create the account on first use. |
| `POST /auth/social/{google\|apple\|facebook}` `{token, app, device_id, locale?}` | none | Verify the native sign-in token server-side; sign in or create the account. |
| `POST /auth/phone/verify` `{phone, code}` | pending session | Add the phone to a social sign-in that has none (request the code with `/auth/otp/request` first). Returns a full session. |
| `POST /auth/refresh` `{refresh_token}` | none | Rotate the pair. |
| `POST /auth/logout` | bearer | End this device's session only. 204. |
| `POST /auth/logout-all` | bearer | End every device's session. 204. |

`app` is `rider` or `driver`: it sets `is_rider` / `is_driver` on the account at first use and is the
token's Sanctum ability. Phones are normalised to E.164 again on the server.

Every sign-in answers with the same shape:

```json
{ "access_token": "…", "access_token_expires_at": "2026-10-11T11:00:00+00:00", "refresh_token": "…",
  "user": { "id": 1, "name": null, "email": null, "phone": "+37491440221", "locale": "en", "timezone": null,
            "is_rider": true, "is_driver": false, "status": "active" },
  "needs_phone": false }
```

Errors: 422 (validation; wrong/expired/used code → `errors.code`), 401 (bad refresh or social token),
403 (suspended, deactivated, deleted, admin account), 409 (phone belongs to another account), 429
(throttled, with `Retry-After`), 503 (SMS or sign-in provider unavailable).

## One-time codes

6 digits, valid 5 minutes (`OTP_TTL_MINUTES`), single use, stored only as an HMAC of phone + code,
invalidated after 5 wrong guesses (`OTP_MAX_ATTEMPTS`). A phone has one live code: asking again replaces it.
Codes go out through the `SmsSender` interface; `SMS_DRIVER=log` (default) only writes to the log.
**Open decision:** the real SMS provider is not chosen yet; add an `SmsSender` implementation and a
case in `AppServiceProvider` when it is.

For mobile E2E tests, `OTP_TEST_CODE` + `OTP_TEST_PHONES` give listed numbers a fixed code. It is
honoured only when `APP_ENV` is `local` or `testing`, whatever the config says.

## Tokens

* **Access token**: Sanctum personal access token, 60 minutes (`MOBILE_ACCESS_TTL_MINUTES`), ability
  `rider` or `driver`.
* **Refresh token**: 64 random bytes (base64url), only its SHA-256 stored (`refresh_tokens`), 30 days
  (`MOBILE_REFRESH_TTL_DAYS`). Each login is a *family*. A refresh marks the old token used and issues a
  new pair in the same family. Presenting an already-used or revoked token revokes the whole family
  (token-theft detection).
* One session per device: signing in again with the same `device_id` ends that device's previous session;
  other devices are untouched. `logout` ends one device, `logout-all` ends all.
* Suspension, deactivation, deletion: `UserObserver` revokes every access and refresh token at once, and
  sign-in and refresh refuse the account while it is not active.

## Social sign-in

* Google / Apple: the ID token's RS256 signature is checked against the provider's published keys
  (cached 1 hour), then issuer, audience (`GOOGLE_CLIENT_IDS`, `APPLE_CLIENT_IDS`) and expiry.
* Facebook: Graph `debug_token` must say the token is valid and belongs to our app (`FACEBOOK_APP_ID`,
  `FACEBOOK_APP_SECRET`), then the profile is read with it.
* The account is matched on `(provider, provider_user_id)` (`social_accounts`). A **provider-verified**
  Google/Apple email links a new social account to the existing account with that email; an unverified
  email, or any Facebook email, never does.
* No phone on file → `needs_phone: true` and the token carries only `pending-phone:<app>`: it can only
  call `phone/verify` (and logout/refresh). `phone/verify` swaps it for a full session.
* Not done here: the `nonce` check, and `POST /devices` (push tokens), which P8-T4 defines.

## Housekeeping

`mobile-auth:prune` (03:40) removes codes expired for a day and refresh tokens expired for a week;
`sanctum:prune-expired --hours=24` (03:30) removes expired access tokens.
