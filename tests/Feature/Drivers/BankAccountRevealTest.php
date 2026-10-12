<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Bank accounts are masked (last four digits); only finance reveals the full number, and every reveal is logged.
 */
class BankAccountRevealTest extends AdminTestCase
{
    private const NUMBER = 'AM12 3456 7890 4821';

    private DriverProfile $driver;

    private DriverBankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = DriverProfile::factory()->create();
        $this->account = DriverBankAccount::factory()->default()->for($this->driver, 'driver')->create(['bank_name' => 'Ameriabank', 'account_number' => self::NUMBER]);
    }

    private function reveal(User $as, ?DriverBankAccount $account = null, ?DriverProfile $driver = null)
    {
        return $this->actingAs($as)->postJson('/admin/drivers/'.($driver ?? $this->driver)->id.'/bank-accounts/'.($account ?? $this->account)->id.'/reveal');
    }

    public function test_the_page_only_ever_carries_the_masked_number_for_every_role(): void
    {
        foreach ([AdminRole::SuperAdmin, AdminRole::Admin, AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $response = $this->actingAs($this->admin($role))->get('/admin/drivers/'.$this->driver->id.'?tab=bank-accounts')->assertOk();

            $response->assertInertia(fn ($page) => $page->where('driver.bank_accounts', [['id' => $this->account->id, 'bank_name' => 'Ameriabank', 'account_last4' => '4821', 'is_default' => true]]));
            foreach (['AM12', '34567890', '3456'] as $fragment) {
                $this->assertStringNotContainsString($fragment, $response->getContent(), "{$role->value} page must not contain {$fragment}");
            }
        }
    }

    public function test_finance_and_super_admin_reveal_the_full_number_without_it_being_cached(): void
    {
        foreach ([AdminRole::Finance, AdminRole::SuperAdmin] as $role) {
            $response = $this->reveal($this->admin($role))->assertOk()->assertExactJson(['account_number' => 'AM1234567890'.'4821']);

            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    /**
     * @return array<string, array{AdminRole}>
     */
    public static function rolesWithoutReveal(): array
    {
        return ['admin' => [AdminRole::Admin], 'support' => [AdminRole::Support], 'dispatcher' => [AdminRole::Dispatcher]];
    }

    #[DataProvider('rolesWithoutReveal')]
    public function test_every_other_role_gets_403_and_nothing_is_logged(AdminRole $role): void
    {
        $this->reveal($this->admin($role))->assertForbidden()->assertDontSee('4821');

        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.bank_account.revealed')->count());
    }

    public function test_the_page_offers_the_reveal_only_to_roles_that_may(): void
    {
        $template = '/admin/drivers/'.$this->driver->id.'/bank-accounts/{id}/reveal';

        $this->actingAs($this->admin(AdminRole::Finance))->get('/admin/drivers/'.$this->driver->id)->assertInertia(fn ($page) => $page->where('actions.revealBankAccount', $template));
        $this->actingAs($this->admin(AdminRole::Admin))->get('/admin/drivers/'.$this->driver->id)->assertInertia(fn ($page) => $page->where('actions.revealBankAccount', null));
        $this->actingAs($this->admin(AdminRole::Support))->get('/admin/drivers/'.$this->driver->id)->assertInertia(fn ($page) => $page->where('actions.revealBankAccount', null));
    }

    public function test_every_reveal_is_logged_with_who_which_account_and_never_the_number(): void
    {
        $finance = $this->admin(AdminRole::Finance, ['name' => 'Fiona Finance']);
        $this->reveal($finance)->assertOk();
        $this->reveal($finance)->assertOk();

        $entries = ActivityLogEntry::where('description', 'driver.bank_account.revealed')->orderBy('id')->get();
        $this->assertCount(2, $entries, 'one entry per reveal');
        foreach ($entries as $entry) {
            $this->assertSame($finance->id, $entry->causer_id);
            $this->assertSame($this->account->id, $entry->subject_id);
            $this->assertSame(DriverBankAccount::class, $entry->subject_type);
            $this->assertSame('4821', $entry->properties['context']['account_last4']);
            $this->assertSame(sprintf('D-%04d', $this->driver->id), $entry->properties['context']['driver']);
            $this->assertSame('Fiona Finance', $entry->properties['actor_name']);
            $this->assertSame('finance', $entry->properties['actor_role']);
            foreach (['AM12', '3456', '7890', 'AM1234567890'] as $fragment) {
                $this->assertStringNotContainsString($fragment, $entry->toJson(), 'the number must not be in the log');
            }
        }
    }

    public function test_a_refused_reveal_logs_nothing_and_a_missing_account_is_a_404(): void
    {
        $finance = $this->admin(AdminRole::Finance);
        $foreign = DriverBankAccount::factory()->for(DriverProfile::factory()->create(), 'driver')->create();
        $removed = DriverBankAccount::factory()->for($this->driver, 'driver')->create();
        $removed->delete();

        $this->reveal($finance, $foreign)->assertNotFound()->assertDontSee('AM');
        $this->reveal($finance, $removed)->assertNotFound();
        $this->actingAs($finance)->postJson('/admin/drivers/999999/bank-accounts/'.$this->account->id.'/reveal')->assertNotFound();
        $this->actingAs($finance)->postJson('/admin/drivers/'.$this->driver->id.'/bank-accounts/999999/reveal')->assertNotFound();

        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.bank_account.revealed')->count());
    }

    public function test_the_reveal_is_rate_limited_per_admin(): void
    {
        $finance = $this->admin(AdminRole::Finance);
        $other = $this->admin(AdminRole::Finance);

        foreach (range(1, 10) as $i) {
            $this->reveal($finance)->assertOk();
        }
        $this->reveal($finance)->assertStatus(429)->assertHeader('Retry-After');
        $this->app['auth']->forgetGuards();
        $this->reveal($other)->assertOk();

        $this->assertSame(11, ActivityLogEntry::where('description', 'driver.bank_account.revealed')->count(), 'the refused attempt left no entry');
    }

    public function test_guests_are_sent_to_login_and_get_no_number(): void
    {
        $response = $this->postJson('/admin/drivers/'.$this->driver->id.'/bank-accounts/'.$this->account->id.'/reveal');

        $response->assertUnauthorized();
        $this->assertStringNotContainsString('4821', $response->getContent());
    }
}
