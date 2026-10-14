<?php

namespace App\Services\MobileAuth;

use App\Data\MobileSession;
use App\Enums\AppClient;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Push\DeviceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues, rotates and revokes the mobile token pairs. Every login is a "family" (one device); a
 * refresh keeps the family, and replaying an already-used refresh token revokes all of it.
 */
final class MobileSessionService
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    /**
     * A new pair for a login. Signing in again on the same device ends that device's previous session
     * (other devices are untouched). A user without a phone gets a pending session: its token can
     * only be used to add the phone.
     */
    public function issue(User $user, AppClient $app, string $deviceId, ?string $familyId = null): MobileSession
    {
        return DB::transaction(function () use ($user, $app, $deviceId, $familyId) {
            if ($familyId === null) {
                $this->revokeDevice($user, $app, $deviceId);
                // Whoever was registered for pushes on this install is no longer the one holding it.
                $this->devices->revokeOthersOnInstall($user, $app, $deviceId);
            }

            $needsPhone = $user->phone === null;
            $expiresAt = now()->addMinutes(config('taxikosmos.mobile_auth.access_ttl_minutes'));
            $access = $user->createToken("{$app->value}:{$deviceId}", [$needsPhone ? $app->pendingAbility() : $app->value], $expiresAt);

            $refreshToken = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

            RefreshToken::query()->forceCreate([
                'user_id' => $user->getKey(),
                'access_token_id' => $access->accessToken->getKey(),
                'app' => $app,
                'device_id' => $deviceId,
                'family_id' => $familyId ?? (string) Str::uuid(),
                'token_hash' => self::hash($refreshToken),
                'expires_at' => now()->addDays(config('taxikosmos.mobile_auth.refresh_ttl_days')),
            ]);

            return new MobileSession($access->plainTextToken, $expiresAt, $refreshToken, $user, $needsPhone);
        });
    }

    /**
     * Exchange a refresh token for a new pair. Returns null when the token is unknown, expired,
     * revoked or already used; a used or revoked token also ends its whole family (it was copied).
     * The caller turns null into a 401 outside this transaction, so the revocation is kept.
     */
    public function refresh(string $plainToken): ?MobileSession
    {
        return DB::transaction(function () use ($plainToken) {
            $row = RefreshToken::query()->where('token_hash', self::hash($plainToken))->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            if ($row->used_at !== null || $row->revoked_at !== null) {
                $this->revokeFamily($row->family_id);

                return null;
            }

            $user = User::query()->find($row->user_id);

            if ($row->expires_at->isPast() || $user === null || ! $user->isActive() || $user->is_admin) {
                $this->revokeFamily($row->family_id);

                return null;
            }

            $row->forceFill(['used_at' => now()])->save();
            PersonalAccessToken::query()->whereKey($row->access_token_id)->delete();

            return $this->issue($user, $row->app, $row->device_id, $row->family_id);
        });
    }

    /**
     * Sign out the device behind this access token; other devices keep their sessions.
     */
    public function revokeCurrent(PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($token) {
            $family = RefreshToken::query()->where('access_token_id', $token->getKey())->value('family_id');

            $family !== null ? $this->revokeFamily($family) : $token->delete();
        });
    }

    /**
     * Sign out every device ("log out everywhere", suspension, deletion). Push registrations end with the sessions, except
     * when `$keepPushDevices` is set: a suspended person still gets the one push that says so, and a later login on the same
     * phone moves the token to its new owner anyway.
     */
    public function revokeAll(User $user, bool $keepPushDevices = false): void
    {
        DB::transaction(function () use ($user, $keepPushDevices) {
            $user->tokens()->delete();
            RefreshToken::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);

            if (! $keepPushDevices) {
                $this->devices->revokeAllOf($user->getKey());
            }
        });
    }

    /**
     * The refresh row of the session this access token belongs to (its device and family).
     */
    public function currentSession(PersonalAccessToken $token): ?RefreshToken
    {
        return RefreshToken::query()->where('access_token_id', $token->getKey())->first();
    }

    public function revokeFamily(string $familyId): void
    {
        // The installs this family signed in on stop receiving pushes for this person.
        RefreshToken::query()->where('family_id', $familyId)->get(['user_id', 'app', 'device_id'])->unique(fn (RefreshToken $row) => "{$row->user_id}:{$row->app->value}:{$row->device_id}")
            ->each(fn (RefreshToken $row) => $this->devices->revokeInstall($row->user_id, $row->app, $row->device_id));

        $tokenIds = RefreshToken::query()->where('family_id', $familyId)->whereNotNull('access_token_id')->pluck('access_token_id');

        PersonalAccessToken::query()->whereKey($tokenIds)->delete();
        RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function revokeDevice(User $user, AppClient $app, string $deviceId): void
    {
        RefreshToken::query()
            ->where('user_id', $user->getKey())->where('app', $app->value)->where('device_id', $deviceId)
            ->whereNull('revoked_at')->distinct()->pluck('family_id')
            ->each(fn (string $family) => $this->revokeFamily($family));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
