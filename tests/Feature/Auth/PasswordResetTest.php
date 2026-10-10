<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Admin\AdminTestCase;

class PasswordResetTest extends AdminTestCase
{
    private const NEW_PASSWORD = 'N3w-Strong!Passphrase';

    /** @var list<string> Passwords the fake breach API reports as leaked. */
    private array $breached = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // haveibeenpwned k-anonymity range API: report only the passwords listed in $breached.
        Http::fake(function ($request) {
            $prefix = substr($request->url(), -5);
            $lines = collect($this->breached)
                ->map(fn (string $password) => strtoupper(sha1($password)))
                ->filter(fn (string $hash) => str_starts_with($hash, $prefix))
                ->map(fn (string $hash) => substr($hash, 5).':42');

            return Http::response($lines->implode("\n"));
        });
    }

    private function admin_(): User
    {
        return $this->admin(AdminRole::Finance, ['email' => 'morgan@taxikosmos.test', 'password' => 'Old-Passw0rd!xyz']);
    }

    public function test_an_admin_gets_a_reset_link_by_email(): void
    {
        Notification::fake();
        $admin = $this->admin_();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'MORGAN@taxikosmos.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', trans(Password::RESET_LINK_SENT));

        Notification::assertSentTo($admin, ResetPassword::class, function (ResetPassword $notification) use ($admin) {
            return str_contains($notification->toMail($admin)->actionUrl, '/reset-password/'.$notification->token.'?email=');
        });
    }

    public function test_unknown_and_non_admin_emails_get_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        $rider = User::factory()->rider()->create(['email' => 'rider@x.test']);

        foreach (['nobody@x.test', 'rider@x.test'] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')
                ->assertSessionHas('status', trans(Password::RESET_LINK_SENT))
                ->assertSessionHasNoErrors();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertNotNull($rider->fresh());
    }

    public function test_a_second_request_within_a_minute_is_throttled(): void
    {
        Notification::fake();
        $this->admin_();

        $this->post('/forgot-password', ['email' => 'morgan@taxikosmos.test'])->assertSessionHasNoErrors();
        $this->post('/forgot-password', ['email' => 'morgan@taxikosmos.test'])->assertSessionHasErrors(['email' => trans(Password::RESET_THROTTLED)]);

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_valid_token_resets_the_password_and_the_new_one_signs_in(): void
    {
        $admin = $this->admin_();
        $token = Password::broker('admins')->createToken($admin);

        $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect('/login')
            ->assertSessionHas('status', trans(Password::PASSWORD_RESET));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/login', ['email' => $admin->email, 'password' => self::NEW_PASSWORD])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_link_expires_after_60_minutes(): void
    {
        $admin = $this->admin_();
        $this->travelTo(now()->startOfMinute());
        $token = Password::broker('admins')->createToken($admin);

        $this->travel(61)->minutes();

        $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors(['email' => trans(Password::INVALID_TOKEN)]);
        $this->assertTrue(Hash::check('Old-Passw0rd!xyz', $admin->fresh()->password));
    }

    public function test_the_link_still_works_just_before_60_minutes(): void
    {
        $admin = $this->admin_();
        $this->travelTo(now()->startOfMinute());
        $token = Password::broker('admins')->createToken($admin);

        $this->travel(59)->minutes();

        $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password));
    }

    public function test_weak_or_mismatched_passwords_are_rejected(): void
    {
        $admin = $this->admin_();
        $token = Password::broker('admins')->createToken($admin);

        foreach (['Sh0rt!Pass' => 'at least 12', 'alllowercase-123!' => 'uppercase and one lowercase', 'NoNumbers-Here!!' => 'one number', 'NoSymbols12345ab' => 'one symbol'] as $weak => $message) {
            $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrors('password');
            $this->assertStringContainsString($message, session('errors')->first('password'), $weak);
        }

        $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'Different!Passw0rd'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('Old-Passw0rd!xyz', $admin->fresh()->password));
    }

    public function test_a_breached_password_is_rejected(): void
    {
        $admin = $this->admin_();
        $token = Password::broker('admins')->createToken($admin);
        $hash = strtoupper(sha1(self::NEW_PASSWORD));
        $this->breached = [self::NEW_PASSWORD];

        $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('password');

        $this->assertStringContainsString('data leak', session('errors')->first('password'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)));
        $this->assertTrue(Hash::check('Old-Passw0rd!xyz', $admin->fresh()->password));
    }
}
