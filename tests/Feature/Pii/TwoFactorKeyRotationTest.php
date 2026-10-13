<?php

namespace Tests\Feature\Pii;

use App\Enums\AdminRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * P3-T2: the two-factor secret and recovery codes are registered PII columns, so `pii:reencrypt` (APP_KEY rotation)
 * rewrites them and an admin's authenticator keeps working.
 */
class TwoFactorKeyRotationTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    public function test_pii_reencrypt_rewrites_the_two_factor_columns_and_the_codes_still_work(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin(AdminRole::Support)->create();
        $codes = $this->enableTwoFactor($admin)['codes'];
        $before = DB::table('users')->where('id', $admin->id)->first();

        $this->artisan('pii:reencrypt --dry-run')->assertSuccessful();
        $this->artisan('pii:reencrypt')->assertSuccessful();

        $after = DB::table('users')->where('id', $admin->id)->first();
        $this->assertNotSame($before->two_factor_secret, $after->two_factor_secret, 'rewritten with a fresh IV');
        $this->assertNotSame($before->two_factor_recovery_codes, $after->two_factor_recovery_codes);

        $fresh = $admin->fresh();
        $this->assertSame($codes, $fresh->recoveryCodes());
        $this->assertTrue($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->otp($fresh));
    }
}
