<?php

namespace App\Services\Account;

use App\Exceptions\MobileAuthException;
use App\Models\User;
use App\Services\MobileAuth\OtpService;
use Illuminate\Support\Facades\DB;

/**
 * What a signed-in rider or driver may change about their own account. The phone is the sign-in identity, so it changes only
 * after a code sent to the new number is entered; the same rules as sign-in keep one account per number.
 */
final class AccountProfileService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * @param  array{name?: ?string, email?: ?string, locale?: ?string}  $changes
     */
    public function update(User $user, array $changes): User
    {
        $email = array_key_exists('email', $changes) ? $changes['email'] : $user->email;

        $user->fill(array_intersect_key($changes, array_flip(['name', 'locale'])));

        if ($email !== $user->email) {
            // An address typed into the app is not proven to be theirs: it never counts as verified (and so never lets a
            // provider-verified sign-in with the same address into this account, see MobileAuthService).
            $user->forceFill(['email' => $email, 'email_verified_at' => null]);
        }

        $user->save();

        return $user;
    }

    /**
     * Send a code to the new number. A number that already belongs to an account gets no code, and the answer is the same,
     * so the call cannot be used to find out who has an account.
     *
     * @param  string  $phone  E.164
     */
    public function requestPhoneChange(User $user, string $phone): void
    {
        $phone === $user->phone && throw MobileAuthException::phoneUnchanged();

        if (User::withTrashed()->wherePhone($phone)->exists()) {
            return;
        }

        $this->otp->request($phone);
    }

    /**
     * @param  string  $phone  E.164
     */
    public function confirmPhoneChange(User $user, string $phone, string $code): User
    {
        $this->otp->verify($phone, $code);

        return DB::transaction(function () use ($user, $phone) {
            // The same lock sign-in takes for this number: a sign-in creating an account for it cannot interleave.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["phone:{$phone}"]);

            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            User::withTrashed()->wherePhone($phone)->whereKeyNot($locked->getKey())->exists() && throw MobileAuthException::phoneTaken();

            $locked->forceFill(['phone' => $phone])->save();

            return $locked;
        });
    }
}
