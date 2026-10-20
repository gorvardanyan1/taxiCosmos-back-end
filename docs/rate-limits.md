# Rate limits

Named rate limiters and other request throttles. Add every new limiter here (task rule).

| Limiter / throttle | Applies to | Limit | Key | On limit | Defined in |
| --- | --- | --- | --- | --- | --- |
| `login` (`throttle:login`) | `POST /login` (admin sign-in) | 5 requests per minute — successful sign-ins count too; **and** `RATE_LIMIT_LOGIN_IP` (30) per minute per address across all emails | lower-cased email + IP; IP alone | The sign-in page (an Inertia request) gets a redirect back with an `email` error: "Too many login attempts. Please try again in N seconds." Any other client gets a `429`. Both carry `Retry-After` | `App\Providers\FortifyServiceProvider` |
| Fortify login lockout | `POST /login` | 5 **failed** attempts per minute | email + IP | Same `email` error | Fortify (`LoginRateLimiter`), on top of `throttle:login` |
| Password-reset broker throttle | `POST /forgot-password` | 1 reset email per minute per admin | admin account | `email` error "Please wait before retrying." | `config/auth.php` → `passwords.admins.throttle` |
| `otp-request` (`throttle:otp-request`) | `POST /api/v1/auth/otp/request` and `POST /api/v1/me/phone/request` (the code to a new number) | 1 per minute per phone + IP; 5 per hour per phone; 30 per hour per IP | normalised E.164 phone (falls back to a hash of the raw input) and IP | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `otp-verify` (`throttle:otp-verify`) | `POST /api/v1/auth/otp/verify`, `POST /api/v1/auth/phone/verify`, `POST /api/v1/me/phone/verify` | 10 per minute per phone + IP; 30 per hour per phone; 30 per minute per IP | same | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| Authenticator code reuse | a code from the app | each code works once (the step it belongs to is remembered for as long as it could still be accepted) | the account's secret | the code is refused like a wrong one | `App\Auth\SingleUseTotpProvider` |
| OTP attempt cap | a single code | 5 wrong guesses, then the code is dead | the code | `422` "invalid or expired" | `App\Services\MobileAuth\OtpService` |
| `social-login` (`throttle:social-login`) | `POST /api/v1/auth/social/{provider}` | 20 per minute | IP | 429 JSON | `App\Providers\AppServiceProvider` |
| `auth-refresh` (`throttle:auth-refresh`) | `POST /api/v1/auth/refresh` | 30 per minute | IP | 429 JSON | `App\Providers\AppServiceProvider` |
| `bank-account-reveal` (`throttle:bank-account-reveal`) | `POST /admin/drivers/{id}/bank-accounts/{accountId}/reveal` | 10 per minute | admin | 429 | `App\Providers\AppServiceProvider` |
| `admin` (`throttle:admin`) | every page and action under `/admin`, and `/two-factor-setup` | `RATE_LIMIT_ADMIN` (300) per minute | signed-in admin | 429 with `Retry-After` (the template's "Too many requests" card) | `App\Providers\AppServiceProvider` |
| `admin-auth` (`throttle:admin-auth`) | every endpoint Fortify registers (`POST /forgot-password`, `/reset-password`, `/logout`, two-factor, …), except `POST /login` which has its own limiter | `RATE_LIMIT_ADMIN_AUTH` (30) per minute | IP + path | 429 with `Retry-After` | `config/fortify.php` → `App\Providers\AppServiceProvider` |
| `password-confirm` (`throttle:password-confirm`) | the actions that ask for the account password again: `POST /two-factor-setup`, `PUT /admin/account/password`, `DELETE /admin/account/sessions`, `DELETE /admin/account/two-factor`, `POST /admin/account/two-factor/recovery-codes`, `POST /admin/settings/users/{id}/two-factor/reset` | 5 per minute (all four share it; each attempt is a password guess) | signed-in admin | 429 with `Retry-After` | `App\Providers\AppServiceProvider` |
| `two-factor-confirm` (`throttle:two-factor-confirm`) | `POST /two-factor-setup/confirm` (the code that finishes the setup) | 5 per minute | signed-in admin | 429 with `Retry-After` | `App\Providers\AppServiceProvider` |
| `two-factor` (`throttle:two-factor`) | `POST /two-factor-challenge` (the code at sign-in; a recovery code counts too) | 5 per minute, however many sign-ins are started | the account (`login.id`), IP before the password step | 429 with `Retry-After` | `App\Providers\FortifyServiceProvider` (Fortify names it in `config/fortify.php` but does not define it) |
| `api` (`throttle:api`) | every authenticated `/api/v1` route (the `auth:sanctum` groups: logout, phone verify, trips) | `RATE_LIMIT_API` (120) per minute | signed-in rider/driver (carrier NATs share addresses, so not the IP) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `payment` (`throttle:payment`) | payment writes: `POST /api/v1/payment-methods`, `DELETE /api/v1/payment-methods/{id}`, `POST /api/v1/payment-methods/{id}/default`, `POST /api/v1/account/outstanding/pay` (P6-T2); attach it to every later charge and refund route | `RATE_LIMIT_PAYMENT` (10) per minute | signed-in user (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `fare-estimate` (`throttle:fare-estimate`) | `POST /api/v1/trips/fare-estimate` (every call stores a quote) | 30 per minute | rider (falls back to IP) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `driver-location` (`throttle:driver-location`) | `POST /api/v1/driver/location` (the REST fallback for driver pings; the Node service takes the normal flow) | `RATE_LIMIT_DRIVER_LOCATION` (30) per minute | signed-in driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `driver-availability` (`throttle:driver-availability`) | `POST /api/v1/driver/availability` (online / offline) | `RATE_LIMIT_DRIVER_AVAILABILITY` (10) per minute | signed-in driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `app-config` (`throttle:app-config`) | `GET /api/v1/app-config` (public: asked at every app start, before sign-in; P14-T4) | `RATE_LIMIT_APP_CONFIG` (60) per minute | IP address | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `geo` (`throttle:geo`) | `GET /api/v1/geo/autocomplete`, `/places/{id}`, `/reverse`, `/route` (each call that misses the cache is billed by the maps provider) | `RATE_LIMIT_GEO` (60) per minute, all four endpoints share it (after `throttle:api`) | signed-in rider/driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `devices` (`throttle:devices`) | `POST /api/v1/devices`, `DELETE /api/v1/devices/{id}` (push token registration) | `RATE_LIMIT_DEVICES` (30) per minute (after `throttle:api`) | signed-in rider/driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `support-tickets` (`throttle:support-tickets`) | `GET/POST /api/v1/support-tickets`, `GET /api/v1/support-tickets/{id}`, `POST /api/v1/support-tickets/{id}/messages` | `RATE_LIMIT_SUPPORT_TICKETS` (20) per minute (after `throttle:api`) | signed-in rider/driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `driver-onboarding` (`throttle:driver-onboarding`) | `POST /api/v1/driver/application`, `PUT /driver/profile`, `/driver/vehicles`, `/driver/bank-accounts`, `GET /driver/documents` | `RATE_LIMIT_DRIVER_ONBOARDING` (30) per minute (after `throttle:api`) | signed-in driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `driver-documents` (`throttle:driver-documents`) | `POST /api/v1/driver/documents` (a file upload) | `RATE_LIMIT_DRIVER_DOCUMENTS` (20) per minute | signed-in driver (IP as fallback) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `socket-auth` (`throttle:socket-auth`) | `POST /api/v1/internal/socket-auth` (the Node service asks who owns a token; service token + allowed address required first) | `RATE_LIMIT_SOCKET_AUTH` (1200) per minute | calling address | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `socket-ticket` (`throttle:socket-ticket`) | `GET /admin/socket-ticket` (admin socket ticket) | `RATE_LIMIT_SOCKET_TICKET` (20) per minute | signed-in admin | 429 with `Retry-After` | `App\Providers\AppServiceProvider` |

Every rate-limited response is a `429` with a `Retry-After` header (seconds). Browser pages render the template's
"Too many requests" card (`Errors/Show`) and keep the header; the sign-in form shows the "Too many login attempts"
banner instead. The `admin`, `api`, `payment`, `admin-auth` and `login` per-IP ceilings are tunable through `.env`
(`RATE_LIMIT_*`, see `config/security.php`); the endpoint-specific limiters above are fixed in code.

Behind a reverse proxy set `TRUSTED_PROXIES`, or every client shares the proxy's address and one noisy visitor
exhausts the per-IP budgets for all of them.

Notes:

- Unknown or non-admin emails on `POST /forgot-password` never send mail and get the same success
  message as a real admin, so the endpoint cannot be used to discover accounts.
- The OTP request and verify limits are separate on purpose: asking for codes cannot use up (or hide) the
  guessing budget. Location pings get their limiter in P8-T2; payment writes attach `throttle:payment` (P6-T2); reading the cards and the balance only counts against `api`.
