<?php

namespace Tests\Support;

use App\Models\User;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor state for tests, built through Fortify's own actions so it is exactly what production stores.
 */
trait InteractsWithTwoFactor
{
    /**
     * A confirmed two-factor setup for the account. Returns the secret and the eight recovery codes.
     * Confirming spends the current code (Fortify accepts each code string once), so a test that signs in
     * afterwards uses otp($user, 1) or otp($user, -1): the neighbouring codes are valid too.
     *
     * @return array{secret: string, codes: list<string>}
     */
    protected function enableTwoFactor(User $user): array
    {
        app(EnableTwoFactorAuthentication::class)($user);
        app(ConfirmTwoFactorAuthentication::class)($user, $this->otp($user));

        return ['secret' => $this->secretOf($user), 'codes' => $user->recoveryCodes()];
    }

    protected function secretOf(User $user): string
    {
        return Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    }

    /**
     * The code the authenticator app shows right now (or $steps 30-second steps later; -1 is the previous one).
     */
    protected function otp(User $user, int $steps = 0): string
    {
        $google = app(Google2FA::class);

        return $google->oathTotp($this->secretOf($user), $google->getTimestamp() + $steps);
    }
}
