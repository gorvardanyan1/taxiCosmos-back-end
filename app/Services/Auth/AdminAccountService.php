<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * What an admin does with their own account (P13-T3): profile, password, and the browsers they are signed in on.
 *
 *  - a password change asks for the current password and signs every other browser out at once (their session stamp no longer matches);
 *  - ending the other sessions asks for the password too (a stolen session alone cannot do it);
 *  - the list of sessions is real only when sessions live in the database (SESSION_DRIVER=database); with another driver it
 *    shows just this browser, but "log out other sessions" still works because other sessions stop matching the password hash;
 *  - each change is audited (no passwords, hashes or session ids).
 */
final class AdminAccountService
{
    /** Sessions listed at most (the newest first). */
    private const MAX_SESSIONS = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function updateProfile(User $admin, string $name, string $timezone): void
    {
        $old = ['name' => $admin->name, 'timezone' => $admin->timezone];
        $admin->forceFill(['name' => $name, 'timezone' => $timezone])->save();

        $old === ['name' => $name, 'timezone' => $timezone]
            ?: $this->audit->record(actor: $admin, action: 'account.profile_updated', target: $admin, old: $old, new: ['name' => $name, 'timezone' => $timezone]);
    }

    public function changePassword(User $admin, string $current, string $new): void
    {
        $this->checkPassword($admin, $current, 'current_password');

        DB::transaction(function () use ($admin, $new) {
            // The session check (AuthenticateSession) re-stamps this browser with the new hash at the end of the request;
            // every other browser still carries the old stamp and is signed out on its next click.
            $admin->forceFill(['password' => $new])->save();
            $this->forgetOtherSessions($admin);
            $this->audit->record(actor: $admin, action: 'account.password_changed', target: $admin);
        });
    }

    public function logoutOtherSessions(User $admin, string $password): int
    {
        $this->checkPassword($admin, $password, 'password');

        return DB::transaction(function () use ($admin, $password) {
            $count = count($this->sessions($admin)) - 1;
            Auth::guard('web')->logoutOtherDevices($password);
            $this->forgetOtherSessions($admin);
            $this->audit->record(actor: $admin, action: 'account.other_sessions_ended', target: $admin, context: ['sessions' => max($count, 0)]);

            return max($count, 0);
        });
    }

    /**
     * @return list<array{id: string, device: string, ip: string, last_active_at: string, is_current: bool}>
     */
    public function sessions(User $admin, ?Request $request = null): array
    {
        $request ??= request();
        $current = $request->session()->getId();
        $rows = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->orderByDesc('last_activity')->limit(self::MAX_SESSIONS)->get(['id', 'ip_address', 'user_agent', 'last_activity'])->all()
            : [];

        $sessions = array_map(fn ($row) => [
            // An opaque label: the real session id is a credential and never leaves the server.
            'id' => substr(hash('sha256', $row->id), 0, 16),
            'device' => $this->device((string) $row->user_agent),
            'ip' => (string) $row->ip_address,
            'last_active_at' => now()->setTimestamp((int) $row->last_activity)->toIso8601String(),
            'is_current' => $row->id === $current,
        ], $rows);

        if (! in_array(true, array_column($sessions, 'is_current'), true)) {
            array_unshift($sessions, ['id' => 'current', 'device' => $this->device((string) $request->userAgent()), 'ip' => (string) $request->ip(), 'last_active_at' => now()->toIso8601String(), 'is_current' => true]);
        }

        return $sessions;
    }

    private function forgetOtherSessions(User $admin): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->where('id', '!=', request()->session()->getId())->delete();
        }
    }

    private function checkPassword(User $admin, string $password, string $field): void
    {
        if ($admin->password === null || ! Hash::check($password, $admin->password)) {
            throw ValidationException::withMessages([$field => __('The password is incorrect.')]);
        }
    }

    /** "Chrome · macOS" from a user agent; nothing else of the string is shown. */
    private function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => __('Unknown browser'),
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Unknown system'),
        };

        return "{$browser} · {$system}";
    }
}
