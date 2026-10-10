<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Admin sign-ins, sign-outs (including the idle timeout) and failed sign-ins go to the audit log.
 * The password that was typed is never read, let alone stored.
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
}
