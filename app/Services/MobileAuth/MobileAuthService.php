<?php

namespace App\Services\MobileAuth;

use App\Data\MobileSession;
use App\Data\SocialIdentity;
use App\Enums\AppClient;
use App\Enums\SocialProvider;
use App\Exceptions\MobileAuthException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is signing in: resolves (or creates) the account for a verified phone or social identity and
 * hands the result to MobileSessionService. The phone and the social id each map to exactly one
 * account; an advisory lock per identity makes a retried or concurrent first sign-in create one row.
 */
final class MobileAuthService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly MobileSessionService $sessions,
        private readonly SocialVerifier $social,
    ) {}

    /**
     * @param  string  $phone  E.164
     */
    public function loginWithPhone(AppClient $app, string $phone, string $code, string $deviceId, ?string $locale): MobileSession
    {
        $this->otp->verify($phone, $code);

        return DB::transaction(function () use ($app, $phone, $deviceId, $locale) {
            $this->lock("phone:{$phone}");

            $user = User::withTrashed()->wherePhone($phone)->first();

            if ($user === null) {
                $user = $this->createUser(['phone' => $phone, 'locale' => $locale]);
            }

            return $this->signIn($user, $app, $deviceId);
        });
    }

    public function loginWithSocial(SocialProvider $provider, AppClient $app, string $token, string $deviceId, ?string $locale): MobileSession
    {
        $identity = $this->social->verify($provider, $token);

        return DB::transaction(function () use ($provider, $app, $identity, $deviceId, $locale) {
            $this->lock("social:{$provider->value}:{$identity->providerUserId}");

            $account = SocialAccount::query()->where('provider', $provider->value)->where('provider_user_id', $identity->providerUserId)->first();
            $user = $account?->user ?? $this->userForNewSocialAccount($provider, $identity, $locale);

            if ($account === null) {
                $account = (new SocialAccount)->forceFill(['provider' => $provider, 'provider_user_id' => $identity->providerUserId]);
                $account->user()->associate($user);
            }

            $account->email = $identity->email;
            $account->save();

            return $this->signIn($user, $app, $deviceId);
        });
    }

    /**
     * Adds a verified phone to an account that signed in socially without one, and swaps its limited
     * session for a full one.
     *
     * @param  string  $phone  E.164
     */
    public function attachPhone(User $user, PersonalAccessToken $current, string $phone, string $code): MobileSession
    {
        $session = $this->sessions->currentSession($current);

        if ($session === null || ! $current->can($session->app->pendingAbility())) {
            throw MobileAuthException::notPendingPhone();
        }

        $this->otp->verify($phone, $code);

        return DB::transaction(function () use ($user, $session, $phone) {
            $this->lock("phone:{$phone}");

            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $locked->phone === null || throw MobileAuthException::phoneAlreadySet();
            User::withTrashed()->wherePhone($phone)->exists() && throw MobileAuthException::phoneTaken();

            $locked->forceFill(['phone' => $phone])->save();
            $this->sessions->revokeFamily($session->family_id);

            return $this->sessions->issue($locked, $session->app, $session->device_id);
        });
    }

    private function signIn(User $user, AppClient $app, string $deviceId): MobileSession
    {
        $user->deleted_at === null || throw MobileAuthException::accountUnavailable();
        $user->is_admin && throw MobileAuthException::adminAccount();
        $user->isActive() || throw MobileAuthException::accountSuspended();

        $user->forceFill([$app->capabilityColumn() => true, 'last_login_at' => now()])->save();

        return $this->sessions->issue($user, $app, $deviceId);
    }

    /**
     * A provider-verified email may link a new social account to the existing account with that
     * email (Google and Apple only; Facebook cannot vouch for it). Otherwise it is a new user.
     */
    private function userForNewSocialAccount(SocialProvider $provider, SocialIdentity $identity, ?string $locale): User
    {
        $trusted = $identity->email !== null && $identity->emailVerified && $provider !== SocialProvider::Facebook;
        $existing = $identity->email === null ? null : User::withTrashed()->whereRaw('lower(email) = ?', [mb_strtolower($identity->email)])->first();

        // Only an address the account has proven (a provider-verified one) can be claimed by a provider's verified sign-in: an
        // address merely typed into the profile of an account would otherwise hand that account to its real owner's sign-in.
        if ($existing !== null && $trusted && $existing->email_verified_at !== null) {
            return $existing;
        }

        // The email goes on the new account only when it is verified and not already someone else's.
        return $this->createUser([
            'name' => $identity->name,
            'email' => $trusted && $existing === null ? $identity->email : null,
            'email_verified_at' => $trusted && $existing === null ? now() : null,
            'locale' => $locale,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(array $attributes): User
    {
        $user = new User;
        $user->forceFill(array_filter($attributes, fn ($value) => $value !== null));
        $user->save();

        return $user;
    }

    /**
     * Serialises first sign-ins for one identity until the surrounding transaction ends.
     */
    private function lock(string $key): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
