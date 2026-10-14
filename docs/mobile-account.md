# Mobile account and profile API (P3-T5)

All calls are `/api/v1`, JSON, with the Sanctum bearer token of a rider or driver session (`docs/mobile-auth.md`).

| Call | Does |
| --- | --- |
| `GET /me` | The signed-in person: the one shape the apps read on start. Also open to a session that still has to add its phone, and to a blocked account (see below). |
| `PATCH /me` | `name`, `email`, `locale` (only what is sent changes; `email: null` removes it). A changed email is stored lower-case and is **not verified** (`email_verified: false`). Nothing else can be changed here: not the status, the modes, the ratings or the phone. |
| `POST /me/phone/request` `{phone}` | Sends a code to the **new** number. Always `202` for a valid number, so the call cannot show whether a number has an account (a number that belongs to another account just gets no code). The current number is `422`. Limited like sign-in (`otp-request`). |
| `POST /me/phone/verify` `{phone, code}` | Changes the phone when the code for that number is right (`422` otherwise; `409` if the number was taken in the meantime). Answers with the new `/me`. Limited like sign-in (`otp-verify`). |
| `GET/POST /saved-places`, `GET/PATCH/DELETE /saved-places/{id}` | The rider's saved places (rider app only). `label` is `home`, `work` or `other`; `name` ≤ 80, `address` ≤ 255; `lat` −90…90 and `lng` −180…180, always together. At most 20 per person (`SAVED_PLACES_MAX`): the 21st is `422` with `code: saved_places_limit_reached`. Another person's id is `404`. |

## The `/me` shape

The same `user` object is returned by every sign-in (`/auth/otp/verify`, `/auth/social/*`, `/auth/refresh`) and by `GET /me`.
Fixtures of every case (rider, pending driver, approved driver, suspended user) are in `tests/Fixtures/api/me/*.json`; the tests
compare against them.

```json
{ "user": {
  "id": 1, "name": "Ani Hakobyan", "phone": "+374*****443", "email": "ani@example.com", "email_verified": true,
  "locale": "hy", "timezone": "Asia/Yerevan", "status": "active",
  "modes": { "is_rider": true, "is_driver": false },
  "driver": null,
  "rider": { "rating": { "average": 4.85, "count": 12 }, "outstanding_balance": { "amount": 0, "currency": "AMD" }, "default_payment_method": null }
} }
```

* `phone` keeps the country code and the last three digits. `null` while a social sign-in has not added a phone.
* `driver` (when the person is or applied as a driver): `verification_status` (`pending|approved|rejected|expired`),
  `availability`, `documents` (the **required** documents that are not yet approved, each `{type, status, reason}` with status
  `missing|pending|rejected|expired`; `reason` is the reviewer's text for a rejected one), `primary_vehicle`
  (`{id, make, model, year, plate_number, color, vehicle_class, status}` or `null`), `debt_limit_reached`.
* `rider` (when the person is a rider): `rating`, `outstanding_balance` (minor units + currency), `default_payment_method`.
* Not available yet, so constant until their task lands: `debt_limit_reached` is always `false` (driver balances, P11-T3),
  `outstanding_balance` is `0` and `default_payment_method` is `null` (payments, P6).

## Blocked accounts

An account that is not `active` (suspended, deactivated, pending deletion) that still holds a token may call only `GET /me`,
`POST /auth/logout`, `POST /auth/logout-all` and the support endpoints (routes named `support.*`). Every other `/api/v1` call is
refused with `403` and a machine-readable `code` the app switches on:

```json
{ "message": "This account is suspended.", "code": "account_suspended" }
```

Codes: `account_suspended`, `account_deactivated`, `account_pending_deletion`, `admin_account`. The same `code` comes with the
`403` of a refused sign-in (`account_suspended`, `account_unavailable`, `admin_account`). Note that suspending an account from the
admin also ends its sessions (P4), so in practice the app sees `401` on its next call and `403` + code when it signs in again;
the guard above covers any token that outlives that.
