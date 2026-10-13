<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\InteractsWithTwoFactor;

/**
 * P3-T2: switching two-factor authentication on. The password is asked again, the secret and eight recovery codes are
 * stored encrypted, and nothing is active until a code from the app has been entered.
 */
class TwoFactorSetupTest extends AdminTestCase
{
    use InteractsWithTwoFactor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Support, ['name' => 'Sam Support']);
    }

    // A real request loads the account fresh; the test client reuses this instance, so refresh it after each action.
    private function start(string $password = 'password')
    {
        return tap($this->actingAs($this->admin)->post('/two-factor-setup', ['password' => $password]), fn () => $this->admin->refresh());
    }

    private function confirm(string $code, bool $saved = true)
    {
        return tap($this->actingAs($this->admin)->post('/two-factor-setup/confirm', ['code' => $code, 'confirmed_saved_codes' => $saved]), fn () => $this->admin->refresh());
    }

    public function test_the_setup_screen_starts_without_a_secret(): void
    {
        $this->actingAs($this->admin)->get('/two-factor-setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorSetup')->where('setup', null)->where('required', false)
            ->where('startUrl', '/two-factor-setup')->where('submitUrl', '/two-factor-setup/confirm'));

        $this->assertNull($this->admin->fresh()->two_factor_secret);
    }

    public function test_starting_needs_the_current_password_and_creates_nothing_without_it(): void
    {
        $this->start('wrong-password')->assertSessionHasErrors(['password' => 'The password is incorrect.']);
        $this->start('')->assertSessionHasErrors('password');

        $fresh = $this->admin->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertSame(0, ActivityLogEntry::where('description', 'admin.two_factor.setup_started')->count());
    }

    public function test_starting_stores_an_encrypted_secret_and_eight_distinct_recovery_codes_but_activates_nothing(): void
    {
        $this->start()->assertRedirect('/two-factor-setup');

        $fresh = $this->admin->fresh();
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertFalse($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertTrue($fresh->hasPendingTwoFactorSetup());

        $secret = $this->secretOf($fresh);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16}$/', $secret);
        $codes = $fresh->recoveryCodes();
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/', $code);
        }

        // Ciphertext at rest: unreadable in a raw query, and the same format as every other registered column.
        $raw = DB::table('users')->where('id', $fresh->id)->first();
        $this->assertStringNotContainsString($secret, $raw->two_factor_secret);
        $this->assertSame($secret, Crypt::decryptString($raw->two_factor_secret));
        $this->assertStringNotContainsString($codes[0], $raw->two_factor_recovery_codes);
        $this->assertSame($codes, json_decode(Crypt::decryptString($raw->two_factor_recovery_codes), true));
    }

    public function test_the_pending_screen_shows_the_qr_code_the_secret_and_the_codes_and_keeps_them_on_reload(): void
    {
        $this->start();
        $secret = $this->secretOf($this->admin);
        $codes = $this->admin->fresh()->recoveryCodes();

        foreach ([1, 2] as $visit) {
            $this->actingAs($this->admin)->get('/two-factor-setup')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('setup.secret', $secret)->where('setup.recovery_codes', $codes)
                ->where('setup.qr_svg', fn (string $svg) => str_starts_with($svg, '<svg') && str_contains($svg, '</svg>')));
        }
    }

    public function test_starting_again_before_confirming_replaces_the_pending_secret(): void
    {
        $this->start();
        $first = $this->secretOf($this->admin);

        $this->start()->assertRedirect('/two-factor-setup');

        $this->assertNotSame($first, $this->secretOf($this->admin));
    }

    public function test_a_wrong_code_confirms_nothing_and_shows_the_error_next_to_the_code(): void
    {
        $this->start();
        $right = $this->otp($this->admin);
        $wrong = $right === '000000' ? '111111' : '000000';

        $this->confirm($wrong)->assertSessionHasErrors(['code' => 'The provided two factor authentication code was invalid.']);

        $this->assertNull($this->admin->fresh()->two_factor_confirmed_at);
        $this->assertSame(0, ActivityLogEntry::where('description', 'admin.two_factor.enabled')->count());
    }

    public function test_the_code_must_be_six_digits_and_the_saved_codes_box_must_be_ticked(): void
    {
        $this->start();

        $this->confirm('')->assertSessionHasErrors('code');
        $this->confirm('12345')->assertSessionHasErrors('code');
        $this->confirm('abcdef')->assertSessionHasErrors('code');
        $this->confirm('1234567')->assertSessionHasErrors('code');
        $this->confirm($this->otp($this->admin), saved: false)->assertSessionHasErrors('confirmed_saved_codes');

        $this->assertNull($this->admin->fresh()->two_factor_confirmed_at);
    }

    public function test_a_right_code_turns_it_on_and_is_audited_without_any_secret(): void
    {
        $this->start();

        $this->confirm($this->otp($this->admin))->assertRedirect('/admin/account?tab=security')->assertSessionHas('success', 'Two-factor authentication is on.');

        $fresh = $this->admin->fresh();
        $this->assertTrue($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertNotNull($fresh->two_factor_confirmed_at);

        $entry = ActivityLogEntry::where('description', 'admin.two_factor.enabled')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame($this->admin->id, $entry->subject_id);
        $this->assertSame(['recovery_codes' => 8], $entry->properties['context']);
        $this->assertStringNotContainsString($this->secretOf($fresh), json_encode($entry->properties));
        $this->assertStringNotContainsString($fresh->recoveryCodes()[0], json_encode($entry->properties));
    }

    public function test_starting_is_audited_too(): void
    {
        $this->start();

        $entry = ActivityLogEntry::where('description', 'admin.two_factor.setup_started')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertStringNotContainsString($this->secretOf($this->admin), json_encode($entry->properties));
    }

    public function test_once_it_is_on_the_setup_screen_sends_the_admin_to_my_account_and_starting_or_confirming_again_is_a_conflict(): void
    {
        $this->enableTwoFactor($this->admin);
        $this->admin->refresh();
        $secret = $this->secretOf($this->admin);

        $this->actingAs($this->admin)->get('/two-factor-setup')->assertRedirect('/admin/account?tab=security');
        $this->actingAs($this->admin)->postJson('/two-factor-setup', ['password' => 'password'])->assertStatus(409)->assertJson(['message' => 'Two-factor authentication is already on.']);
        $this->actingAs($this->admin)->postJson('/two-factor-setup/confirm', ['code' => $this->otp($this->admin), 'confirmed_saved_codes' => true])->assertStatus(409);

        $this->assertSame($secret, $this->secretOf($this->admin), 'the active secret was not replaced');
    }

    public function test_confirming_without_starting_is_a_conflict(): void
    {
        $this->actingAs($this->admin)->postJson('/two-factor-setup/confirm', ['code' => '123456', 'confirmed_saved_codes' => true])
            ->assertStatus(409)->assertJson(['message' => 'Start the two-factor setup first.']);
    }

    public function test_a_guest_is_sent_to_sign_in_and_a_rider_has_no_access(): void
    {
        $this->get('/two-factor-setup')->assertRedirect('/login');
        $this->post('/two-factor-setup', ['password' => 'password'])->assertRedirect('/login');
        $this->post('/two-factor-setup/confirm', ['code' => '123456', 'confirmed_saved_codes' => true])->assertRedirect('/login');

        $rider = User::factory()->rider()->create();
        $this->actingAs($rider)->get('/two-factor-setup')->assertRedirect('/login');
        $this->assertNull($rider->fresh()->two_factor_secret);
    }

    public function test_the_password_guesses_and_the_code_guesses_are_each_limited_per_admin(): void
    {
        $this->travelTo(now()->startOfMinute());

        for ($i = 0; $i < 5; $i++) {
            $this->start('wrong')->assertSessionHasErrors('password');
        }
        $limited = $this->start('password');
        $limited->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertNull($this->admin->fresh()->two_factor_secret, 'a throttled request changes nothing');

        $this->travel(61)->seconds();
        $this->start();
        for ($i = 0; $i < 5; $i++) {
            $this->confirm('000000');
        }
        $codeLimited = $this->confirm($this->otp($this->admin));
        $codeLimited->assertStatus(429);
        $this->assertNotNull($codeLimited->headers->get('Retry-After'));
        $this->assertFalse($this->admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_the_secret_and_the_recovery_codes_never_reach_any_page_but_the_pending_setup_and_never_serialise(): void
    {
        $this->enableTwoFactor($this->admin);
        $this->admin->refresh();
        $secret = $this->secretOf($this->admin);
        $codes = $this->admin->recoveryCodes();

        $html = $this->actingAs($this->admin)->get('/admin/account?tab=security')->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString($codes[0], $html);
        $this->assertStringNotContainsString(DB::table('users')->where('id', $this->admin->id)->value('two_factor_secret'), $html, 'not even the ciphertext');

        $array = $this->admin->fresh()->toArray();
        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
    }
}
