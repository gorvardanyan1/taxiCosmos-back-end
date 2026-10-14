<?php

namespace Tests\Feature\Account;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Admin\AdminTestCase;

/**
 * P13-T3 · My Account: profile, change password, active sessions and "log out other sessions". Real PostgreSQL; the sessions
 * list reads the `sessions` table, so these tests run on the database session driver.
 */
class AdminAccountSecurityTest extends AdminTestCase
{
    private const NEW_PASSWORD = 'Brand-New-Pass!2026';

    private User $admin;

    /** @var list<string> Passwords the fake breach API reports as leaked. */
    private array $breached = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // haveibeenpwned k-anonymity range API: report only the passwords listed in $breached.
        Http::fake(function ($request) {
            $prefix = substr($request->url(), -5);

            return Http::response(collect($this->breached)->map(fn (string $p) => strtoupper(sha1($p)))->filter(fn (string $h) => str_starts_with($h, $prefix))->map(fn (string $h) => substr($h, 5).':42')->implode("\n"));
        });
        config(['session.driver' => 'database']);
        $this->admin = $this->admin(AdminRole::Support, ['name' => 'Old Name', 'timezone' => 'UTC']);
    }

    private function otherSession(User $user, string $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36', string $ip = '203.0.113.9'): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => $ip, 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->subHour()->timestamp]);

        return $id;
    }

    private function entries(string $action)
    {
        return ActivityLogEntry::query()->where('description', $action)->get();
    }

    public function test_the_profile_saves_name_and_timezone_and_audits_the_change(): void
    {
        $this->actingAs($this->admin)->patch('/admin/account/profile', ['name' => 'Anna Support', 'timezone' => 'Asia/Yerevan'])
            ->assertRedirect()->assertSessionHas('success', 'Profile updated.');

        $fresh = $this->admin->fresh();
        $this->assertSame(['Anna Support', 'Asia/Yerevan'], [$fresh->name, $fresh->timezone]);
        $entry = $this->entries('account.profile_updated')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame(['name' => 'Old Name', 'timezone' => 'UTC'], $entry->attribute_changes['old']);
        $this->assertSame(['name' => 'Anna Support', 'timezone' => 'Asia/Yerevan'], $entry->attribute_changes['attributes']);
    }

    public function test_saving_an_unchanged_profile_writes_no_audit_entry(): void
    {
        $this->actingAs($this->admin)->patch('/admin/account/profile', ['name' => 'Old Name', 'timezone' => 'UTC'])->assertRedirect();

        $this->assertCount(0, $this->entries('account.profile_updated'));
    }

    public function test_the_profile_ignores_the_email_and_privilege_fields_a_client_adds(): void
    {
        $email = $this->admin->email;
        $this->actingAs($this->admin)->patch('/admin/account/profile', ['name' => 'Anna', 'timezone' => 'UTC', 'email' => 'evil@x.test', 'is_admin' => false, 'status' => 'suspended'])->assertRedirect();

        $fresh = $this->admin->fresh();
        $this->assertSame($email, $fresh->email);
        $this->assertTrue($fresh->is_admin);
        $this->assertSame('active', $fresh->status->value);
    }

    public function test_the_profile_is_validated_and_nothing_is_saved(): void
    {
        foreach ([['name' => '', 'timezone' => 'UTC', 'bad' => 'name'], ['name' => str_repeat('a', 256), 'timezone' => 'UTC', 'bad' => 'name'], ['name' => 'Ok', 'timezone' => 'Mars/Base', 'bad' => 'timezone'], ['name' => 'Ok', 'timezone' => '', 'bad' => 'timezone']] as $case) {
            $bad = array_pop($case);
            $this->actingAs($this->admin)->patch('/admin/account/profile', $case)->assertSessionHasErrors($bad);
        }

        $this->assertSame(['Old Name', 'UTC'], [$this->admin->fresh()->name, $this->admin->fresh()->timezone]);
        $this->assertCount(0, $this->entries('account.profile_updated'));
    }

    public function test_the_password_changes_with_the_current_one_and_ends_the_other_sessions(): void
    {
        $other = $this->otherSession($this->admin);
        $someoneElses = $this->otherSession($this->admin(AdminRole::Support));

        $this->actingAs($this->admin)->put('/admin/account/password', ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $hash = $this->admin->fresh()->password;
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $hash));
        $this->assertFalse(Hash::check('password', $hash));
        $this->assertDatabaseMissing('sessions', ['id' => $other]);
        $this->assertDatabaseHas('sessions', ['id' => $someoneElses]);
        $entry = $this->entries('account.password_changed')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode([$entry->properties, $entry->attribute_changes]));
        $this->assertStringNotContainsString($hash, json_encode([$entry->properties, $entry->attribute_changes]));
    }

    public function test_after_a_password_change_this_browser_stays_in_and_a_browser_with_the_old_hash_is_signed_out(): void
    {
        config(['session.driver' => 'array']);
        $this->actingAs($this->admin)->get('/admin/account')->assertOk();
        $oldStamp = session('password_hash_web');
        $this->assertNotEmpty($oldStamp);

        $this->actingAs($this->admin)->put('/admin/account/password', ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertRedirect();
        $newStamp = session('password_hash_web');
        $this->assertNotSame($oldStamp, $newStamp, 'this browser\'s session was re-stamped with the new password');

        // This browser keeps working; a browser still carrying the old stamp is thrown out (works with any session driver).
        $this->flushSession()->withSession(['password_hash_web' => $newStamp])->actingAs($this->admin->fresh())->get('/admin/account')->assertOk();
        $this->flushSession()->withSession(['password_hash_web' => $oldStamp])->actingAs($this->admin->fresh())->get('/admin/account')->assertRedirect('/login');
    }

    public function test_logging_out_other_sessions_signs_out_a_browser_with_the_old_stamp_whatever_the_session_driver(): void
    {
        config(['session.driver' => 'array']);
        $this->actingAs($this->admin)->get('/admin/account')->assertOk();
        $oldStamp = session('password_hash_web');

        $this->actingAs($this->admin)->delete('/admin/account/sessions', ['password' => 'password'])->assertRedirect();
        $newStamp = session('password_hash_web');
        $this->assertNotSame($oldStamp, $newStamp);

        $this->flushSession()->withSession(['password_hash_web' => $newStamp])->actingAs($this->admin->fresh())->get('/admin/account')->assertOk();
        $this->flushSession()->withSession(['password_hash_web' => $oldStamp])->actingAs($this->admin->fresh())->get('/admin/account')->assertRedirect('/login');
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $hash = $this->admin->password;
        $other = $this->otherSession($this->admin);

        $this->actingAs($this->admin)->put('/admin/account/password', ['current_password' => 'not-it', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors(['current_password' => 'The password is incorrect.']);

        $this->assertSame($hash, $this->admin->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => $other]);
        $this->assertCount(0, $this->entries('account.password_changed'));
    }

    public function test_the_new_password_must_be_strong_confirmed_and_different(): void
    {
        $cases = [
            'password' => [['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'no symbols' => [['current_password' => 'password', 'password' => 'Abcdefghijkl1234', 'password_confirmation' => 'Abcdefghijkl1234'], 'password'],
            'mismatch' => [['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD.'x'], 'password'],
            'empty' => [['current_password' => '', 'password' => '', 'password_confirmation' => ''], 'current_password'],
        ];
        $hash = $this->admin->password;

        foreach ($cases as [$body, $field]) {
            $this->actingAs($this->admin)->put('/admin/account/password', $body)->assertSessionHasErrors($field);
        }

        $this->assertSame($hash, $this->admin->fresh()->password);
        $this->assertCount(0, $this->entries('account.password_changed'));
    }

    public function test_the_new_password_cannot_be_the_current_one(): void
    {
        $same = $this->admin(AdminRole::Support, ['password' => self::NEW_PASSWORD]);

        $this->actingAs($same)->put('/admin/account/password', ['current_password' => self::NEW_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('password');

        $this->assertCount(0, $this->entries('account.password_changed'));
    }

    public function test_a_breached_new_password_is_refused(): void
    {
        $this->breached = [self::NEW_PASSWORD];

        $this->actingAs($this->admin)->put('/admin/account/password', ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('password');

        $this->assertStringContainsString('data leak', session('errors')->first('password'));
        $this->assertTrue(Hash::check('password', $this->admin->fresh()->password));
    }

    public function test_logging_out_other_sessions_needs_the_password_and_keeps_this_session_and_other_admins(): void
    {
        $one = $this->otherSession($this->admin);
        $two = $this->otherSession($this->admin);
        $someoneElses = $this->otherSession($this->admin(AdminRole::Support));

        $this->actingAs($this->admin)->delete('/admin/account/sessions', ['password' => 'wrong'])->assertSessionHasErrors(['password' => 'The password is incorrect.']);
        $this->actingAs($this->admin)->delete('/admin/account/sessions', [])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('sessions', ['id' => $one]);
        $this->assertCount(0, $this->entries('account.other_sessions_ended'));

        // The wrong-password requests above left browser sessions of their own in the table: those are other sessions too.
        $others = DB::table('sessions')->where('user_id', $this->admin->id)->count();
        $this->assertGreaterThanOrEqual(2, $others);

        $this->actingAs($this->admin)->delete('/admin/account/sessions', ['password' => 'password'])->assertRedirect()->assertSessionHas('success', 'Other sessions were signed out.');

        $this->assertDatabaseMissing('sessions', ['id' => $one]);
        $this->assertDatabaseMissing('sessions', ['id' => $two]);
        $this->assertDatabaseHas('sessions', ['id' => $someoneElses]);
        $entry = $this->entries('account.other_sessions_ended')->sole();
        $this->assertSame($others, $entry->properties['context']['sessions']);
    }

    public function test_the_account_page_lists_the_real_sessions_without_their_ids(): void
    {
        $other = $this->otherSession($this->admin);
        $this->otherSession($this->admin(AdminRole::Support), ip: '198.51.100.1');

        $this->actingAs($this->admin)->get('/admin/account?tab=security')->assertInertia(function (AssertableInertia $page) use ($other) {
            $page->component('Account/Show')->where('sessions_listed', true)->has('sessions', 2)
                ->where('sessions.0.is_current', true)
                ->where('sessions.1.device', 'Chrome · macOS')
                ->where('sessions.1.ip', '203.0.113.9')
                ->where('sessions.1.is_current', false)
                ->where('actions.updateProfile', '/admin/account/profile')
                ->where('actions.updatePassword', '/admin/account/password')
                ->where('actions.logoutOtherSessions', '/admin/account/sessions');
            $this->assertStringNotContainsString($other, json_encode($page->toArray()));
        });
    }

    public function test_without_database_sessions_only_this_browser_is_listed(): void
    {
        config(['session.driver' => 'array']);

        $this->actingAs($this->admin)->get('/admin/account?tab=security', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Gecko/20100101 Firefox/127.0'])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessions_listed', false)->has('sessions', 1)->where('sessions.0.is_current', true)->where('sessions.0.device', 'Firefox · Windows'));
    }

    public function test_the_routes_are_for_signed_in_admins_only(): void
    {
        foreach ([['patch', '/admin/account/profile'], ['put', '/admin/account/password'], ['delete', '/admin/account/sessions']] as [$method, $url]) {
            $this->{$method}($url, [])->assertRedirect('/login');
            $this->actingAs(User::factory()->rider()->create())->{$method.'Json'}($url, [])->assertForbidden();
        }
    }

    public function test_password_and_session_actions_are_rate_limited(): void
    {
        $this->travelTo(now()->startOfMinute());
        foreach (range(1, 5) as $_) {
            $this->actingAs($this->admin)->delete('/admin/account/sessions', ['password' => 'wrong'])->assertSessionHasErrors('password');
        }

        $this->actingAs($this->admin)->delete('/admin/account/sessions', ['password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_the_routes_run_the_session_check_that_signs_other_browsers_out(): void
    {
        foreach (['admin.account.password', 'admin.account.sessions.destroy', 'admin.account'] as $name) {
            $this->assertContains('auth.session', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }
}
