<?php

namespace Tests\Feature\Auth;

use App\Auth\SingleUseTotpProvider;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * P3-T2: every authenticator code works once. Fortify's own guard let a code from the next 30-second step pass twice
 * and forgot a used code after 60 seconds although it stays valid for 90; this provider replaces it.
 */
class SingleUseTotpProviderTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private TwoFactorAuthenticationProvider $provider;

    private Google2FA $google;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = app(TwoFactorAuthenticationProvider::class);
        $this->google = app(Google2FA::class);
    }

    private function code(int $steps = 0, string $secret = self::SECRET): string
    {
        return $this->google->oathTotp($secret, $this->google->getTimestamp() + $steps);
    }

    public function test_fortify_uses_this_provider(): void
    {
        $this->assertInstanceOf(SingleUseTotpProvider::class, $this->provider);
    }

    public function test_the_current_code_is_accepted_once(): void
    {
        $code = $this->code();

        $this->assertTrue($this->provider->verify(self::SECRET, $code));
        $this->assertFalse($this->provider->verify(self::SECRET, $code));
    }

    public function test_the_previous_and_the_next_codes_are_accepted_once_each(): void
    {
        foreach ([-1, 1] as $steps) {
            $code = $this->code($steps);

            $this->assertTrue($this->provider->verify(self::SECRET, $code), "step {$steps}, first use");
            $this->assertFalse($this->provider->verify(self::SECRET, $code), "step {$steps}, replay");
            $this->assertFalse($this->provider->verify(self::SECRET, $code), "step {$steps}, second replay");
        }
    }

    public function test_a_code_two_steps_away_is_never_accepted(): void
    {
        $this->assertFalse($this->provider->verify(self::SECRET, $this->code(2)));
        $this->assertFalse($this->provider->verify(self::SECRET, $this->code(-2)));
    }

    public function test_wrong_malformed_and_empty_codes_are_refused_and_remembered_for_nothing(): void
    {
        $right = $this->code();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach ([$wrong, '', '12345', '1234567', 'abcdef', '12 3456', ' '.$right, $right.' '] as $input) {
            $this->assertFalse($this->provider->verify(self::SECRET, $input), "'{$input}'");
        }

        $this->assertTrue($this->provider->verify(self::SECRET, $right), 'a refused attempt does not use up the real code');
    }

    public function test_a_used_code_stays_blocked_for_as_long_as_it_could_still_be_valid(): void
    {
        $code = $this->code(-1);
        $this->assertTrue($this->provider->verify(self::SECRET, $code));

        // A code is acceptable for up to 90 seconds; Fortify forgot it after 60.
        $this->travel(80)->seconds();

        $this->assertFalse($this->provider->verify(self::SECRET, $code));
    }

    public function test_the_same_six_digits_are_judged_per_secret(): void
    {
        $other = 'KRSXG5CTMVRXEZLU';
        // Find a moment where two secrets happen to show the same digits is not practical; check the cache key is per secret instead.
        $code = $this->code();
        $this->assertTrue($this->provider->verify(self::SECRET, $code));

        $this->assertNull(Cache::get('admin.2fa_code.'.hash('sha256', $other.'|'.$code)));
        $this->assertSame($this->google->getTimestamp(), Cache::get('admin.2fa_code.'.hash('sha256', self::SECRET.'|'.$code)), 'the step the code belongs to is what is remembered');
    }

    public function test_a_wrong_secret_never_matches(): void
    {
        $this->assertFalse($this->provider->verify('KRSXG5CTMVRXEZLU', $this->code()));
    }
}
