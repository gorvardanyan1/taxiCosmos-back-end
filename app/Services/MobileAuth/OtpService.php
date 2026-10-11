<?php

namespace App\Services\MobileAuth;

use App\Contracts\SmsSender;
use App\Exceptions\MobileAuthException;
use App\Exceptions\SmsDeliveryException;
use App\Models\OtpCode;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * One-time phone codes: 6 digits, valid for 5 minutes, stored only as an HMAC, thrown away after 5
 * wrong guesses, and good for one use. A phone has one live code: asking again replaces it.
 */
final class OtpService
{
    public function __construct(private readonly SmsSender $sms, private readonly BlindIndex $blindIndex) {}

    /**
     * @param  string  $phone  E.164
     */
    public function request(string $phone): void
    {
        $code = $this->codeFor($phone);
        $phoneHash = $this->blindIndex->hash($phone);

        $otp = DB::transaction(function () use ($phone, $phoneHash, $code) {
            OtpCode::query()->where('phone_hash', $phoneHash)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return OtpCode::query()->forceCreate([
                'phone_hash' => $phoneHash,
                'code_hash' => $this->hashCode($phone, $code),
                'expires_at' => now()->addMinutes(config('taxikosmos.mobile_auth.otp.ttl_minutes')),
            ]);
        });

        try {
            $this->sms->send($phone, "Your TaxiKosmos code is {$code}. It expires in ".config('taxikosmos.mobile_auth.otp.ttl_minutes').' minutes.');
        } catch (SmsDeliveryException) {
            $otp->forceFill(['consumed_at' => now()])->save();

            throw MobileAuthException::smsUnavailable();
        }
    }

    /**
     * Check a code and use it up. Wrong guesses are counted even though the caller then throws, so
     * the counting happens in its own committed transaction.
     *
     * @param  string  $phone  E.164
     */
    public function verify(string $phone, string $code): void
    {
        $accepted = DB::transaction(function () use ($phone, $code) {
            $otp = OtpCode::query()
                ->where('phone_hash', $this->blindIndex->hash($phone))
                ->whereNull('consumed_at')->where('expires_at', '>', now())
                ->latest('id')->lockForUpdate()->first();

            if ($otp === null) {
                return false;
            }

            if (hash_equals($otp->code_hash, $this->hashCode($phone, $code))) {
                $otp->forceFill(['consumed_at' => now()])->save();

                return true;
            }

            $otp->attempts++;

            if ($otp->attempts >= config('taxikosmos.mobile_auth.otp.max_attempts')) {
                $otp->consumed_at = now();
            }

            $otp->save();

            return false;
        });

        $accepted || throw MobileAuthException::invalidCode();
    }

    private function codeFor(string $phone): string
    {
        return $this->isTestPhone($phone)
            ? (string) config('taxikosmos.mobile_auth.otp.test_code')
            : str_pad((string) random_int(0, 10 ** config('taxikosmos.mobile_auth.otp.length') - 1), config('taxikosmos.mobile_auth.otp.length'), '0', STR_PAD_LEFT);
    }

    /**
     * Test phones get the fixed code only outside production-like environments.
     */
    private function isTestPhone(string $phone): bool
    {
        $fixed = (string) config('taxikosmos.mobile_auth.otp.test_code');

        if ($fixed === '' || ! app()->environment(['local', 'testing'])) {
            return false;
        }

        $listed = array_filter(array_map(E164PhoneNumber::tryNormalize(...), config('taxikosmos.mobile_auth.otp.test_phones')));

        return in_array($phone, $listed, true);
    }

    private function hashCode(string $phone, string $code): string
    {
        return $this->blindIndex->hash("otp|{$phone}|{$code}");
    }
}
