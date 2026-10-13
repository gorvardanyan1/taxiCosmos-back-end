<?php

namespace App\Services\Auth;

use App\Exceptions\TwoFactorStateException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

/**
 * What admins do with two-factor authentication (P3-T2): set it up, confirm it with a code from their app, switch it off,
 * regenerate the recovery codes, and (super admin) reset someone else's after a lost device. Fortify supplies the
 * secret, the codes and the sign-in challenge; this class adds the rules around them:
 *
 *  - every change asks for the account password again (a stolen session alone cannot touch it);
 *  - roles that must have it (config security.two_factor.required_roles) cannot switch it off;
 *  - a setup counts only after a code from the app was entered (two_factor_confirmed_at), so an abandoned one locks no one out;
 *  - each action is one transaction under the account's row lock, with its audit entry (no secrets, no codes).
 */
final class AdminTwoFactorService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EnableTwoFactorAuthentication $enable,
        private readonly ConfirmTwoFactorAuthentication $confirm,
        private readonly DisableTwoFactorAuthentication $disable,
        private readonly GenerateNewRecoveryCodes $generateCodes,
    ) {}

    /**
     * Start (or restart) the setup: a fresh secret and eight recovery codes, not active until confirmed.
     */
    public function start(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $locked = $this->lock($user);
            $this->checkPassword($locked, $password);

            if ($locked->hasEnabledTwoFactorAuthentication()) {
                throw TwoFactorStateException::alreadyEnabled();
            }

            ($this->enable)($locked, force: true);
            $this->audit->record(actor: $locked, action: 'admin.two_factor.setup_started', target: $locked);
        });
    }

    /**
     * Activate it with a code from the authenticator app.
     */
    public function confirm(User $user, string $code): void
    {
        DB::transaction(function () use ($user, $code) {
            $locked = $this->lock($user);

            if ($locked->hasEnabledTwoFactorAuthentication()) {
                throw TwoFactorStateException::alreadyEnabled();
            }

            if (! $locked->hasPendingTwoFactorSetup()) {
                throw TwoFactorStateException::notStarted();
            }

            try {
                ($this->confirm)($locked, $code);
            } catch (ValidationException) {
                // Fortify files its message under a named error bag; the page reads the default one. The same sentence is what
                // Fortify's sign-in challenge says for a wrong code, so the language files carry it once.
                throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
            }

            $this->audit->record(actor: $locked, action: 'admin.two_factor.enabled', target: $locked, context: ['recovery_codes' => count($locked->recoveryCodes())]);
        });
    }

    /**
     * Switch it off (or cancel a setup that was never confirmed). Never for a role that must have it.
     */
    public function disable(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $locked = $this->lock($user);

            if ($locked->requiresTwoFactor()) {
                throw new AuthorizationException(__('Your role requires two-factor authentication, so it cannot be switched off.'));
            }

            $this->checkPassword($locked, $password);

            if ($locked->two_factor_secret === null) {
                throw TwoFactorStateException::notEnabled();
            }

            $wasActive = $locked->hasEnabledTwoFactorAuthentication();
            ($this->disable)($locked);
            $this->audit->record(actor: $locked, action: $wasActive ? 'admin.two_factor.disabled' : 'admin.two_factor.setup_cancelled', target: $locked);
        });
    }

    /**
     * A new set of eight recovery codes; the old set stops working at once. The codes are returned once, to be shown once.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $password): array
    {
        return DB::transaction(function () use ($user, $password) {
            $locked = $this->lock($user);
            $this->checkPassword($locked, $password);

            if (! $locked->hasEnabledTwoFactorAuthentication()) {
                throw TwoFactorStateException::notEnabled();
            }

            ($this->generateCodes)($locked);
            $this->audit->record(actor: $locked, action: 'admin.two_factor.recovery_codes_regenerated', target: $locked, context: ['recovery_codes' => count($locked->recoveryCodes())]);

            return $locked->recoveryCodes();
        });
    }

    /**
     * A super admin clears another admin's two-factor authentication (lost phone). Needs the super admin's password and a
     * reason. If the target's role requires it they are sent to the setup screen on their next request.
     */
    public function reset(User $actor, User $target, string $password, string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $password, $reason) {
            $this->checkPassword($actor, $password);

            if (! $actor->isSuperAdmin()) {
                throw new AuthorizationException(__('Only a super admin can reset another admin\'s two-factor authentication.'));
            }

            if ($actor->is($target)) {
                throw TwoFactorStateException::resetOwnAccount();
            }

            $locked = $this->lock($target);

            if (! $locked->is_admin) {
                throw TwoFactorStateException::notAnAdmin();
            }

            if ($locked->two_factor_secret === null) {
                throw TwoFactorStateException::notEnabled();
            }

            ($this->disable)($locked);
            $this->audit->record(actor: $actor, action: 'admin.two_factor.reset', target: $locked, reason: $reason, reasonRequired: true);
        });
    }

    /**
     * What the setup screen shows while a setup is pending: the QR code, the secret to type in by hand, and the recovery codes.
     *
     * @return array{qr_svg: string, secret: string, recovery_codes: list<string>}|null
     */
    public function pendingSetup(User $user): ?array
    {
        if (! $user->hasPendingTwoFactorSetup()) {
            return null;
        }

        return [
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'recovery_codes' => $user->recoveryCodes(),
        ];
    }

    private function lock(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function checkPassword(User $user, string $password): void
    {
        if ($user->password === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }
    }
}
