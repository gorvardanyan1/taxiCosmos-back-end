# Rate limits

Named rate limiters and other request throttles. Add every new limiter here (task rule).

| Limiter / throttle | Applies to | Limit | Key | On limit | Defined in |
| --- | --- | --- | --- | --- | --- |
| `login` (`throttle:login`) | `POST /login` (admin sign-in) | 5 requests per minute — successful sign-ins count too | lower-cased email + IP | Redirect back with an `email` validation error: "Too many login attempts. Please try again in N seconds." | `App\Providers\FortifyServiceProvider` |
| Fortify login lockout | `POST /login` | 5 **failed** attempts per minute | email + IP | Same `email` error | Fortify (`LoginRateLimiter`), on top of `throttle:login` |
| Password-reset broker throttle | `POST /forgot-password` | 1 reset email per minute per admin | admin account | `email` error "Please wait before retrying." | `config/auth.php` → `passwords.admins.throttle` |

Notes:

- Unknown or non-admin emails on `POST /forgot-password` never send mail and get the same success
  message as a real admin, so the endpoint cannot be used to discover accounts.
- Mobile (`/api/v1`) limiters (OTP request/verify, location pings, payments) are added by their tasks
  (P3-T3, P8-T2, P9-T3).
