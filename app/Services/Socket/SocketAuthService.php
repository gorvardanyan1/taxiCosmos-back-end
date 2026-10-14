<?php

namespace App\Services\Socket;

use App\Enums\AppClient;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tells the Node real-time service who a socket belongs to (P8-T3), so it needs no access to this database. Answers for a mobile
 * Sanctum access token or for a single-use admin ticket; anything that is not a live, active, allowed identity is null (a 401).
 * Checked on every call, so a revoked, expired, suspended, deleted or de-roled identity is refused at once.
 */
class SocketAuthService
{
    private const TICKET_PREFIX = 'socket-ticket:';

    /**
     * @return array{user_id: int, kind: string, app: string, modes: list<string>, status: string, expires_at: ?string}|null
     */
    public function identifyToken(string $plainToken): ?array
    {
        $token = PersonalAccessToken::findToken($plainToken);
        $user = $token?->tokenable;

        if ($token === null || ! $user instanceof User || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        // The ability must be named on the token (a wildcard is not a mobile session); a session that still has to add its phone
        // (pending-phone:*) holds neither ability and gets nothing.
        $app = collect(AppClient::cases())->first(fn (AppClient $app) => in_array($app->value, $token->abilities, true) && $user->{$app->capabilityColumn()});

        if ($app === null || $user->is_admin || ! $user->isActive()) {
            return null;
        }

        return [
            'user_id' => $user->id,
            'kind' => 'mobile',
            'app' => $app->value,
            'modes' => collect(AppClient::cases())->filter(fn (AppClient $mode) => $user->{$mode->capabilityColumn()})->map->value->values()->all(),
            'status' => $user->status->value,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Mint a ticket for a signed-in admin. Only its hash is stored; it can be exchanged once, within the TTL.
     *
     * @return array{ticket: string, expires_in: int}
     */
    public function issueAdminTicket(User $admin): array
    {
        $ticket = Str::random(48);
        $ttl = (int) config('security.socket.ticket_ttl_seconds');

        Cache::put(self::TICKET_PREFIX.hash('sha256', $ticket), $admin->id, $ttl);

        return ['ticket' => $ticket, 'expires_in' => $ttl];
    }

    /**
     * @return array{user_id: int, kind: string, app: string, modes: list<string>, roles: list<string>, status: string, expires_at: ?string}|null
     */
    public function identifyTicket(string $ticket): ?array
    {
        $key = self::TICKET_PREFIX.hash('sha256', $ticket);

        // The first redeemer wins: the marker is set atomically, so a replayed or raced ticket is refused.
        if (! Cache::add($key.':used', true, (int) config('security.socket.ticket_ttl_seconds'))) {
            return null;
        }

        $adminId = Cache::get($key);
        Cache::forget($key);
        $admin = $adminId === null ? null : User::query()->find($adminId);

        if (! $admin instanceof User || ! $admin->hasAdminAccess()) {
            return null;
        }

        return [
            'user_id' => $admin->id,
            'kind' => 'admin',
            'app' => 'admin',
            'modes' => ['admin'],
            'roles' => $admin->getRoleNames()->values()->all(),
            'status' => $admin->status->value,
            'expires_at' => null,
        ];
    }
}
