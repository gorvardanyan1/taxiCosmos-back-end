<?php

namespace Tests\Feature\Audit;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;

class AuthActivityTest extends AuditTestCase
{
    private const PASSWORD = 'Corr3ct-Horse!Battery';

    private function admin1(): User
    {
        return $this->admin(AdminRole::Finance, ['name' => 'Morgan Webb', 'email' => 'morgan@taxikosmos.test', 'password' => self::PASSWORD]);
    }

    private function signIn(string $email, string $password, string $ip = '10.10.4.18')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'QA Browser'])->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function test_a_sign_in_is_logged_once_with_actor_ip_and_user_agent(): void
    {
        $admin = $this->admin1();

        $this->signIn('morgan@taxikosmos.test', self::PASSWORD)->assertRedirect('/admin');

        $entry = ActivityLogEntry::where('description', 'auth.login')->sole();
        $this->assertSame('auth', $entry->log_name);
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame('Morgan Webb', $entry->properties['actor_name']);
        $this->assertSame('finance', $entry->properties['actor_role']);
        $this->assertSame('10.10.4.18', $entry->properties['ip']);
        $this->assertSame('QA Browser', $entry->properties['user_agent']);
        $this->assertSame(1, ActivityLogEntry::count());
    }

    public function test_a_sign_out_is_logged(): void
    {
        $admin = $this->admin1();
        $this->signIn('morgan@taxikosmos.test', self::PASSWORD);

        $this->post('/logout')->assertRedirect();

        $entry = ActivityLogEntry::where('description', 'auth.logout')->sole();
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertGuest();
    }

    public function test_the_idle_timeout_sign_out_is_logged(): void
    {
        $admin = $this->admin1();
        $this->signIn('morgan@taxikosmos.test', self::PASSWORD);
        $this->travel(61)->minutes();

        $this->get('/admin')->assertRedirect('/login');

        $this->assertSame($admin->id, ActivityLogEntry::where('description', 'auth.logout')->sole()->causer_id);
    }

    public function test_a_failed_sign_in_is_logged_with_the_email_but_never_the_password(): void
    {
        $admin = $this->admin1();

        $this->signIn('MORGAN@taxikosmos.test', 'my-wrong-Password-123!')->assertSessionHasErrors();

        $entry = ActivityLogEntry::where('description', 'auth.login_failed')->sole();
        $this->assertSame('auth', $entry->log_name);
        $this->assertSame($admin->id, $entry->causer_id, 'The known account is the actor.');
        $this->assertSame('morgan@taxikosmos.test', $entry->properties['context']['email'], 'The sign-in form lower-cases the email.');
        $this->assertSame('10.10.4.18', $entry->properties['ip']);
        $this->assertStringNotContainsString('my-wrong-Password-123!', ActivityLogEntry::all()->toJson());
        $this->assertSame(0, ActivityLogEntry::where('description', 'auth.login')->count());
    }

    public function test_a_failed_sign_in_for_an_unknown_email_has_no_actor(): void
    {
        $this->signIn('nobody@example.com', 'whatever-123');

        $entry = ActivityLogEntry::where('description', 'auth.login_failed')->sole();
        $this->assertNull($entry->causer_id);
        $this->assertSame('nobody@example.com', $entry->properties['context']['email']);
    }

    public function test_every_failed_attempt_is_a_separate_entry(): void
    {
        $this->admin1();

        foreach (range(1, 3) as $i) {
            $this->signIn('morgan@taxikosmos.test', "wrong-{$i}");
        }

        $this->assertSame(3, ActivityLogEntry::where('description', 'auth.login_failed')->count());
    }

    public function test_a_rider_cannot_sign_in_to_the_admin_and_the_attempt_is_logged(): void
    {
        User::factory()->rider()->create(['email' => 'rider@example.com', 'password' => self::PASSWORD]);

        $this->signIn('rider@example.com', self::PASSWORD)->assertSessionHasErrors();

        $this->assertSame(1, ActivityLogEntry::where('description', 'auth.login_failed')->count());
        $this->assertSame(0, ActivityLogEntry::where('description', 'auth.login')->count());
    }
}
