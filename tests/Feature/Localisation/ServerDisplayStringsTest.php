<?php

namespace Tests\Feature\Localisation;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Text the server itself sends to the browser for display (role names, placeholders for missing
 * names, who did what) is in the admin's language too. The i18n guard cannot see PHP, so these
 * tests read the real props, and StaticServerTextGuardTest stops new untranslated literals.
 */
class ServerDisplayStringsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(string $locale, AdminRole $role = AdminRole::SuperAdmin): User
    {
        return User::factory()->admin($role)->create(['locale' => $locale]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function roleLabels(): array
    {
        return [
            'super admin' => ['super_admin', 'Суперадминистратор', 'Գլխավոր ադմինիստրատոր'],
            'admin' => ['admin', 'Администратор', 'Ադմինիստրատոր'],
            'support' => ['support', 'Поддержка', 'Աջակցություն'],
            'finance' => ['finance', 'Финансы', 'Ֆինանսներ'],
            'dispatcher' => ['dispatcher', 'Диспетчер', 'Դիսպետչեր'],
        ];
    }

    #[DataProvider('roleLabels')]
    public function test_the_signed_in_admins_role_label_is_in_their_language(string $role, string $ru, string $hy): void
    {
        $english = ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'support' => 'Support', 'finance' => 'Finance', 'dispatcher' => 'Dispatcher'][$role];

        foreach (['en' => $english, 'ru' => $ru, 'hy' => $hy] as $locale => $label) {
            $this->actingAs($this->admin($locale, AdminRole::from($role)))->get('/admin/account')
                ->assertInertia(fn (Assert $page) => $page->where('auth.user.roleLabel', $label)->where('auth.user.role', $role));
        }
    }

    public function test_the_role_list_on_the_users_page_is_translated_and_keeps_stable_names(): void
    {
        $this->actingAs($this->admin('ru'))->get('/admin/settings/users')->assertInertia(fn (Assert $page) => $page
            ->where('roles.0', ['name' => 'super_admin', 'label' => 'Суперадминистратор'])
            ->where('roles.4', ['name' => 'dispatcher', 'label' => 'Диспетчер']));
    }

    public function test_a_rider_without_a_name_gets_a_placeholder_in_the_admins_language(): void
    {
        $rider = User::factory()->rider()->create(['name' => null]);

        foreach (['en' => 'Unnamed rider', 'ru' => 'Пассажир без имени', 'hy' => 'Անանուն ուղևոր'] as $locale => $placeholder) {
            $this->actingAs($this->admin($locale))->get('/admin/riders')->assertInertia(fn (Assert $page) => $page->where('riders.data.0.name', $placeholder));
            $this->actingAs($this->admin($locale))->get("/admin/riders/{$rider->id}")->assertInertia(fn (Assert $page) => $page->where('rider.name', $placeholder));
        }
    }

    public function test_the_rider_activity_sentence_is_built_in_the_admins_language(): void
    {
        $rider = User::factory()->rider()->create();
        $actor = User::factory()->admin(AdminRole::Support)->create(['name' => 'Riley Chen']);
        $audit = app(AuditLogger::class);
        $audit->record($actor, 'rider.suspended', $rider, 'Chargeback fraud', ['status' => 'active'], ['status' => 'suspended']);
        $audit->record($actor, 'rider.reactivated', $rider, 'Appeal accepted');
        $audit->record(null, 'rider.updated', $rider);

        $expected = [
            'en' => ['rider.updated by System', 'rider.reactivated by Riley Chen: Appeal accepted', 'rider.suspended by Riley Chen: Chargeback fraud'],
            'ru' => ['rider.updated, выполнил(а): Система', 'rider.reactivated, выполнил(а): Riley Chen. Причина: Appeal accepted', 'rider.suspended, выполнил(а): Riley Chen. Причина: Chargeback fraud'],
            'hy' => ['rider.updated՝ կատարող՝ Համակարգ', 'rider.reactivated՝ կատարող՝ Riley Chen։ Պատճառ՝ Appeal accepted', 'rider.suspended՝ կատարող՝ Riley Chen։ Պատճառ՝ Chargeback fraud'],
        ];

        foreach ($expected as $locale => $descriptions) {
            $this->actingAs($this->admin($locale))->get("/admin/riders/{$rider->id}?tab=activity")->assertInertia(fn (Assert $page) => $page
                ->where('rider.records.activity', fn ($rows) => collect($rows)->pluck('description')->all() === $descriptions));
        }
    }

    public function test_an_activity_log_entry_without_an_actor_shows_the_system_in_the_admins_language(): void
    {
        app(AuditLogger::class)->record(null, 'zone.deactivated');

        foreach (['en' => 'System', 'ru' => 'Система', 'hy' => 'Համակարգ'] as $locale => $name) {
            $this->actingAs($this->admin($locale))->get('/admin/activity-logs')->assertInertia(fn (Assert $page) => $page
                ->where('entries.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['action'] === 'zone.deactivated' && $row['actor']['name'] === $name)));
        }

        $this->assertSame(1, ActivityLogEntry::query()->where('description', 'zone.deactivated')->count());
    }

    public function test_the_stored_audit_text_is_not_translated_it_stays_as_written(): void
    {
        $actor = User::factory()->admin(AdminRole::Support)->create(['name' => 'Riley Chen']);
        app()->setLocale('ru');
        app(AuditLogger::class)->record($actor, 'rider.suspended', User::factory()->rider()->create(), 'Fraud');

        $entry = ActivityLogEntry::query()->where('description', 'rider.suspended')->sole();
        $this->assertSame('support', $entry->properties['actor_role']);
        $this->assertSame('Fraud', $entry->properties['reason']);
        $this->assertSame('Riley Chen', $entry->properties['actor_name']);
    }

    public function test_a_deleted_drivers_account_placeholder_is_translated(): void
    {
        $user = User::factory()->driver()->create();
        $driver = DriverProfile::factory()->for($user, 'user')->create();
        $user->delete();

        foreach (['en' => 'Deleted account', 'ru' => 'Удалённый аккаунт', 'hy' => 'Ջնջված հաշիվ'] as $locale => $name) {
            $this->actingAs($this->admin($locale))->get("/admin/drivers/{$driver->id}")->assertInertia(fn (Assert $page) => $page->where('driver.name', $name));
        }
    }
}
