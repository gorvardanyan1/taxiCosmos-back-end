<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Admin sign-ins, sign-outs (including the idle timeout), failed sign-ins, wrong two-factor codes and recovery codes
 * used to sign in go to the audit log. The password or code that was typed is never read, let alone stored.
 */
class LogAuthActivity
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->guard === 'web' && $event->user instanceof User) {
            $this->audit->record($event->user, 'auth.login', $event->user, log: AuditLogger::AUTH_LOG);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard === 'web' && $event->user instanceof User) {
            $this->audit->record($event->user, 'auth.logout', $event->user, log: AuditLogger::AUTH_LOG);
        }
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $email = $event->credentials['email'] ?? null;
        $email = is_string($email) ? mb_substr(trim($email), 0, 255) : null;

        // Fortify's custom authenticator reports no user, so the account is found by its email:
        // that makes "failed attempts against this admin" filterable by actor.
        $account = $event->user instanceof User ? $event->user : ($email === null ? null : User::query()->where('email', mb_strtolower($email))->first());

        $this->audit->record(
            $account,
            'auth.login_failed',
            context: $email === null || $email === '' ? [] : ['email' => $email],
            log: AuditLogger::AUTH_LOG,
            targetLabel: $email === null || $email === '' ? null : $email,
        );
    }

    /**
     * A recovery code was used instead of an authenticator code (P3-T2). The code itself is never logged.
     */
    public function handleRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record($event->user, 'auth.recovery_code_used', $event->user, context: ['recovery_codes_left' => $event->user->recoveryCodesLeft()], log: AuditLogger::AUTH_LOG);
        }
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record($event->user, 'auth.two_factor_failed', $event->user, log: AuditLogger::AUTH_LOG);
        }
    }
}
