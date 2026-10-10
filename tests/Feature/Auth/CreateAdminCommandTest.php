<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\AdminTestCase;

class CreateAdminCommandTest extends AdminTestCase
{
    private const PASSWORD = 'Str0ng!Admin-Passw';

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

    private function run_(string $email, array $options = [], string $name = 'Ada Admin', string $password = self::PASSWORD, ?string $confirm = null)
    {
        return $this->artisan('admin:create', ['email' => $email, ...$options])
            ->expectsQuestion('Name', $name)
            ->expectsQuestion('Password (min 12 chars, mixed case, number, symbol)', $password)
            ->expectsQuestion('Confirm password', $confirm ?? $password);
    }

    public function test_it_creates_an_active_super_admin_who_can_sign_in(): void
    {
        $this->run_('Ada@TaxiKosmos.test')->expectsOutput('Admin ada@taxikosmos.test created with role super_admin.')->assertSuccessful();

        $admin = User::query()->where('email', 'ada@taxikosmos.test')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertSame('active', $admin->status->value);
        $this->assertSame('Ada Admin', $admin->name);
        $this->assertSame(['super_admin'], $admin->getRoleNames()->all());
        $this->assertNotSame(self::PASSWORD, $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertTrue($admin->isSuperAdmin());

        $this->post('/login', ['email' => 'ada@taxikosmos.test', 'password' => self::PASSWORD])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_role_option_is_respected(): void
    {
        $this->run_('fin@x.test', ['--role' => 'finance'])->assertSuccessful();

        $this->assertSame(['finance'], User::query()->where('email', 'fin@x.test')->sole()->getRoleNames()->all());
    }

    public function test_an_unknown_role_or_bad_email_is_rejected_before_asking_anything(): void
    {
        $this->artisan('admin:create', ['email' => 'x@x.test', '--role' => 'god'])->assertFailed();
        $this->artisan('admin:create', ['email' => 'not-an-email'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_an_email_that_belongs_to_a_non_admin(): void
    {
        $rider = User::factory()->rider()->create(['email' => 'rider@x.test']);

        $this->artisan('admin:create', ['email' => 'RIDER@x.test'])
            ->expectsOutput('The email rider@x.test belongs to a non-admin account; refusing to turn it into an admin.')
            ->assertFailed();

        $fresh = $rider->fresh();
        $this->assertFalse($fresh->is_admin);
        $this->assertSame([], $fresh->getRoleNames()->all());
    }

    public function test_it_refuses_existing_admins_and_soft_deleted_accounts(): void
    {
        $this->admin(AdminRole::Admin, ['email' => 'admin@x.test']);
        $gone = User::factory()->rider()->create(['email' => 'gone@x.test']);
        $gone->delete();

        $this->artisan('admin:create', ['email' => 'admin@x.test'])->expectsOutput('An admin with the email admin@x.test already exists.')->assertFailed();
        $this->artisan('admin:create', ['email' => 'gone@x.test'])->assertFailed();

        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_weak_breached_or_mismatched_passwords_and_empty_names_create_nothing(): void
    {
        $this->run_('a@x.test', password: 'short')->assertFailed();
        $this->run_('b@x.test', confirm: 'Different!Passw0rd')->assertFailed();
        $this->run_('c@x.test', name: '   ')->assertFailed();

        $this->breached = [self::PASSWORD];
        $this->run_('d@x.test')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
