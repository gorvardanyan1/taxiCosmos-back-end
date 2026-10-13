<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\InteractsWithTwoFactor;

/**
 * P3-T2: signing in with two-factor authentication on. The password alone gets nobody in; a code from the app
 * (used once) or a recovery code (used once, and logged) does.
 */
class TwoFactorLoginTest extends AdminTestCase
{
    use InteractsWithTwoFactor;

    private const PASSWORD = 'Corr3ct-Horse!Battery';

    private User $admin;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Finance, ['email' => 'fin@x.test', 'password' => self::PASSWORD, 'name' => 'Fiona Finance']);
        $this->codes = $this->enableTwoFactor($this->admin)['codes'];
        $this->admin->refresh();
    }

    private function password(string $password = self::PASSWORD)
    {
        return $this->post('/login', ['email' => 'fin@x.test', 'password' => $password]);
    }

    private function challenge(array $body)
    {
        return $this->post('/two-factor-challenge', $body);
    }

    public function test_the_password_alone_does_not_sign_in_an_admin_with_two_factor_on(): void
    {
        $this->password()->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
        $this->assertSame($this->admin->id, session('login.id'));
        $this->get('/admin')->assertRedirect('/login');
        $this->assertSame(0, ActivityLogEntry::where('description', 'auth.login')->count(), 'no sign-in is recorded before the second step');
    }

    public function test_an_admin_without_two_factor_still_signs_in_with_the_password_alone(): void
    {
        $this->admin(AdminRole::Support, ['email' => 'sup@x.test', 'password' => self::PASSWORD]);

        $this->post('/login', ['email' => 'sup@x.test', 'password' => self::PASSWORD])->assertRedirect('/admin');

        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_never_reaches_the_second_step(): void
    {
        $this->password('wrong')->assertSessionHasErrors('email');

        $this->assertNull(session('login.id'));
        $this->assertGuest();
    }

    public function test_the_right_code_signs_in_and_records_the_sign_in(): void
    {
        $this->password();

        $this->challenge(['code' => $this->otp($this->admin, 1)])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->admin);
        $this->get('/admin')->assertOk();
        $this->assertNotNull($this->admin->fresh()->last_login_at);
        $entry = ActivityLogEntry::where('description', 'auth.login')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
    }

    public function test_a_wrong_code_signs_nobody_in_shows_the_error_next_to_the_code_and_is_logged(): void
    {
        $this->password();
        $right = $this->otp($this->admin);
        $wrong = $right === '000000' ? '111111' : '000000';

        $this->challenge(['code' => $wrong])->assertRedirect('/two-factor-challenge')
            ->assertSessionHasErrors(['code' => 'The provided two factor authentication code was invalid.']);

        $this->assertGuest();
        $entry = ActivityLogEntry::where('description', 'auth.two_factor_failed')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame('auth', $entry->log_name);
        $this->assertStringNotContainsString($wrong, json_encode($entry->properties), 'the typed code is never stored');
    }

    public function test_a_code_that_was_just_used_cannot_be_used_again(): void
    {
        $code = $this->otp($this->admin, 1);
        $this->password();
        $this->challenge(['code' => $code])->assertRedirect('/admin');
        $this->post('/logout');

        $this->password();
        $this->challenge(['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        // A different code of the same account (the previous 30-second one) is accepted.
        $this->challenge(['code' => $this->otp($this->admin, -1)])->assertRedirect('/admin');
    }

    public function test_a_code_for_another_account_does_not_work(): void
    {
        $other = $this->admin(AdminRole::Admin, ['password' => self::PASSWORD]);
        $this->enableTwoFactor($other);
        $this->password();

        $this->challenge(['code' => $this->otp($other->fresh(), 1)])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_guessing_codes_is_limited_per_account_whatever_the_session(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->password();
        $right = $this->otp($this->admin, 1);
        $wrong = $right === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->challenge(['code' => $wrong])->assertSessionHasErrors('code');
        }

        $limited = $this->challenge(['code' => $right]);
        $limited->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->challenge(['code' => $right])->assertRedirect('/admin');
    }

    public function test_a_recovery_code_signs_in_once_and_is_logged_without_the_code(): void
    {
        $code = $this->codes[0];
        $this->password();

        $this->challenge(['recovery_code' => $code])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->admin);
        $fresh = $this->admin->fresh();
        $this->assertNotContains($code, $fresh->recoveryCodes());
        $this->assertCount(7, $fresh->recoveryCodes());
        $this->assertSame(7, $fresh->recoveryCodesLeft());

        $entry = ActivityLogEntry::where('description', 'auth.recovery_code_used')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame($this->admin->id, $entry->subject_id);
        $this->assertSame('auth', $entry->log_name);
        $this->assertSame(['recovery_codes_left' => 7], $entry->properties['context']);
        $this->assertStringNotContainsString($code, json_encode($entry->properties));
    }

    public function test_a_recovery_code_works_only_once(): void
    {
        $code = $this->codes[0];
        $this->password();
        $this->challenge(['recovery_code' => $code])->assertRedirect('/admin');
        $this->post('/logout');

        $this->password();
        $this->challenge(['recovery_code' => $code])->assertSessionHasErrors(['recovery_code' => 'The provided two factor recovery code was invalid.']);

        $this->assertGuest();
        $this->assertSame(7, $this->admin->fresh()->recoveryCodesLeft());
        $this->assertSame(1, ActivityLogEntry::where('description', 'auth.recovery_code_used')->count());
    }

    public function test_every_recovery_code_works_once_and_a_made_up_one_never(): void
    {
        foreach (['AAAAAAAAAA-BBBBBBBBBB', '', 'not-a-code'] as $invented) {
            $this->password();
            $this->challenge(['recovery_code' => $invented])->assertSessionHasErrors();
            $this->assertGuest();
        }

        $this->assertSame(8, $this->admin->fresh()->recoveryCodesLeft(), 'a wrong guess uses up nothing');
    }

    public function test_two_sign_ins_racing_with_the_same_recovery_code_cannot_both_use_it(): void
    {
        $code = $this->codes[0];
        $first = User::find($this->admin->id);
        $second = User::find($this->admin->id); // loaded before the first one used the code, like a parallel request

        $first->replaceRecoveryCode($code);

        try {
            $second->replaceRecoveryCode($code);
            $this->fail('The second use of the same recovery code must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recovery_code', $e->errors());
        }

        $this->assertSame(7, $this->admin->fresh()->recoveryCodesLeft());
        $this->assertSame(1, ActivityLogEntry::where('description', 'auth.recovery_code_used')->count());
    }

    public function test_the_challenge_screen_needs_the_password_step_first(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');

        // Without the password step there is nobody to challenge: the answer is the (failed) challenge screen, which sends on to sign-in.
        $this->post('/two-factor-challenge', ['code' => $this->otp($this->admin, 1)])->assertRedirect('/two-factor-challenge');
        $this->get('/two-factor-challenge')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_the_challenge_screen_is_shown_with_its_submit_url_after_the_password_step(): void
    {
        $this->password();

        $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/TwoFactorChallenge')->where('submitUrl', '/two-factor-challenge'));
    }

    public function test_a_deactivated_admin_never_gets_to_the_second_step(): void
    {
        $this->admin->forceFill(['status' => 'suspended'])->save();

        $this->password()->assertSessionHasErrors('email');

        $this->assertNull(session('login.id'));
        $this->assertGuest();
    }

    public function test_the_session_is_new_after_the_second_step_and_there_is_no_remember_me(): void
    {
        $this->password();
        $before = session()->getId();

        $response = $this->challenge(['code' => $this->otp($this->admin, 1)]);

        $this->assertNotSame($before, session()->getId());
        $this->assertEmpty(collect($response->headers->getCookies())->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web'))->all());
    }
}
