<?php

namespace App\Auth;

use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * Fortify's TOTP check with a replay guard that actually holds. Fortify remembers the *current* 30-second step when a
 * code is accepted, so a code from the next step (what a phone with a fast clock shows) passes a second time, and it
 * forgets a used code after 60 seconds although a code stays valid for up to 90. Here the step the code really belongs
 * to is remembered, per secret, for as long as the code could still be accepted: every code works once.
 */
class SingleUseTotpProvider extends TwoFactorAuthenticationProvider
{
    /** Length of one TOTP step, seconds (RFC 6238). */
    private const STEP_SECONDS = 30;

    public function verify($secret, $code)
    {
        $code = (string) $code;
        $window = $this->window();
        $step = $this->matchedStep($secret, $code, $window);

        if ($step === null) {
            return false;
        }

        // The cache key never contains the code itself in clear; it is bound to the secret, so two admins who are shown the
        // same six digits do not block each other.
        $key = 'admin.2fa_code.'.hash('sha256', $secret.'|'.$code);
        $lastStep = $this->cache?->get($key);

        if ($lastStep !== null && $step <= $lastStep) {
            return false;
        }

        // A code is acceptable from `window` steps before its step until `window` steps after it.
        $this->cache?->put($key, $step, (2 * $window + 1) * self::STEP_SECONDS + self::STEP_SECONDS);

        return true;
    }

    /**
     * The time step the code belongs to, if it is one of the codes valid right now.
     */
    private function matchedStep(string $secret, string $code, int $window): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = $this->engine->getTimestamp();

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->engine->oathTotp($secret, $now + $offset), $code)) {
                return $now + $offset;
            }
        }

        return null;
    }

    private function window(): int
    {
        $configured = config('fortify-options.two-factor-authentication.window');

        return is_int($configured) ? max(0, $configured) : 1;
    }
}
