# Rate limits

Named rate limiters and other request throttles. Add every new limiter here (task rule).

| Limiter / throttle | Applies to | Limit | Key | On limit | Defined in |
| --- | --- | --- | --- | --- | --- |
| `login` (`throttle:login`) | `POST /login` (admin sign-in) | 5 requests per minute — successful sign-ins count too | lower-cased email + IP | Redirect back with an `email` validation error: "Too many login attempts. Please try again in N seconds." | `App\Providers\FortifyServiceProvider` |
| Fortify login lockout | `POST /login` | 5 **failed** attempts per minute | email + IP | Same `email` error | Fortify (`LoginRateLimiter`), on top of `throttle:login` |
| Password-reset broker throttle | `POST /forgot-password` | 1 reset email per minute per admin | admin account | `email` error "Please wait before retrying." | `config/auth.php` → `passwords.admins.throttle` |
| `otp-request` (`throttle:otp-request`) | `POST /api/v1/auth/otp/request` | 1 per minute per phone + IP; 5 per hour per phone; 30 per hour per IP | normalised E.164 phone (falls back to a hash of the raw input) and IP | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| `otp-verify` (`throttle:otp-verify`) | `POST /api/v1/auth/otp/verify`, `POST /api/v1/auth/phone/verify` | 10 per minute per phone + IP; 30 per hour per phone; 30 per minute per IP | same | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |
| OTP attempt cap | a single code | 5 wrong guesses, then the code is dead | the code | `422` "invalid or expired" | `App\Services\MobileAuth\OtpService` |
| `social-login` (`throttle:social-login`) | `POST /api/v1/auth/social/{provider}` | 20 per minute | IP | 429 JSON | `App\Providers\AppServiceProvider` |
| `auth-refresh` (`throttle:auth-refresh`) | `POST /api/v1/auth/refresh` | 30 per minute | IP | 429 JSON | `App\Providers\AppServiceProvider` |
| `bank-account-reveal` (`throttle:bank-account-reveal`) | `POST /admin/drivers/{id}/bank-accounts/{accountId}/reveal` | 10 per minute | admin | 429 | `App\Providers\AppServiceProvider` |
| `fare-estimate` (`throttle:fare-estimate`) | `POST /api/v1/trips/fare-estimate` (every call stores a quote) | 30 per minute | rider (falls back to IP) | 429 JSON with `Retry-After` | `App\Providers\AppServiceProvider` |

Notes:

- Unknown or non-admin emails on `POST /forgot-password` never send mail and get the same success
  message as a real admin, so the endpoint cannot be used to discover accounts.
- The OTP request and verify limits are separate on purpose: asking for codes cannot use up (or hide) the
  guessing budget. Other mobile limiters (location pings, payments) are added by their tasks (P8-T2, P9-T3).
