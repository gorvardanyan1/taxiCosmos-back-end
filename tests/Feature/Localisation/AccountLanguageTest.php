<?php

namespace Tests\Feature\Localisation;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(?string $locale = 'en', string $role = 'support'): User
    {
        return User::factory()->admin(AdminRole::from($role))->create(['locale' => $locale]);
    }

    public function test_an_admin_switches_language_in_my_account_and_it_is_saved(): void
    {
        $admin = $this->admin('en');

        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru'])->assertRedirect('/admin/account');

        $this->assertSame('ru', $admin->refresh()->locale);
    }

    public function test_the_inertia_request_gets_a_full_page_reload_so_the_new_dictionary_loads(): void
    {
        $admin = $this->admin('en');

        $response = $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true'])->patch('/admin/account/locale', ['locale' => 'hy']);

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', '/admin/account');
    }

    public function test_the_confirmation_after_the_reload_is_in_the_new_language(): void
    {
        $admin = $this->admin('en');

        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru']);
        $page = $this->actingAs($admin->refresh())->get('/admin/account');

        $page->assertInertia(fn ($p) => $p->where('flash.success', 'Язык обновлён.')->where('app.locale', 'ru')->where('profile.locale', 'ru'));
        $this->assertSame('ru', $page->headers->get('Content-Language'));
    }

    public function test_the_account_page_lists_every_language_by_its_own_name_and_the_current_one(): void
    {
        $this->actingAs($this->admin('hy'))->get('/admin/account')->assertInertia(fn ($page) => $page
            ->where('profile.locale', 'hy')
            ->where('locales', [['code' => 'en', 'name' => 'English'], ['code' => 'hy', 'name' => 'Հայերեն'], ['code' => 'ru', 'name' => 'Русский']])
            ->where('actions.updateLocale', '/admin/account/locale'));
    }

    public function test_the_change_is_audited_with_old_and_new_language(): void
    {
        $admin = $this->admin('en');

        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru']);

        $entry = ActivityLogEntry::query()->where('description', 'account.locale_changed')->sole();
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame($admin->id, $entry->subject_id);
        $this->assertSame(['locale' => 'en'], $entry->attribute_changes['old']);
        $this->assertSame(['locale' => 'ru'], $entry->attribute_changes['attributes']);
    }

    public function test_choosing_the_current_language_again_changes_and_logs_nothing(): void
    {
        $admin = $this->admin('ru');

        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru'])->assertRedirect();

        $this->assertSame('ru', $admin->refresh()->locale);
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'account.locale_changed')->count());
    }

    public function test_unsupported_missing_and_malformed_languages_are_refused(): void
    {
        $admin = $this->admin('en');

        foreach ([['locale' => 'de'], ['locale' => ''], [], ['locale' => ['ru']], ['locale' => 'RU']] as $payload) {
            $this->actingAs($admin)->patchJson('/admin/account/locale', $payload)->assertStatus(422)->assertJsonValidationErrors('locale');
        }

        $this->assertSame('en', $admin->refresh()->locale);
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'account.locale_changed')->count());
    }

    public function test_it_changes_only_the_signed_in_admin(): void
    {
        $admin = $this->admin('en');
        $other = $this->admin('en');

        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'hy', 'id' => $other->id, 'user_id' => $other->id]);

        $this->assertSame('hy', $admin->refresh()->locale);
        $this->assertSame('en', $other->refresh()->locale);
    }

    public function test_every_admin_role_may_change_their_own_language_without_a_permission(): void
    {
        foreach (['support', 'finance', 'dispatcher', 'admin', 'super_admin'] as $role) {
            $admin = $this->admin('en', $role);

            $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru'])->assertRedirect();

            $this->assertSame('ru', $admin->refresh()->locale, $role);
        }
    }

    public function test_guests_and_non_admins_cannot_use_it(): void
    {
        $this->patch('/admin/account/locale', ['locale' => 'ru'])->assertRedirect('/login');

        $rider = User::factory()->rider()->create(['locale' => 'en']);
        $this->actingAs($rider)->patch('/admin/account/locale', ['locale' => 'ru'])->assertRedirect('/login');
        $this->assertSame('en', $rider->refresh()->locale);
    }

    public function test_a_deactivated_admin_cannot_change_it(): void
    {
        $admin = $this->admin('en');
        $admin->forceFill(['status' => UserStatus::Deactivated])->saveQuietly();

        $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => 'ru'])->assertRedirect('/login');

        $this->assertSame('en', $admin->refresh()->locale);
    }

    public function test_mail_to_the_admin_is_sent_in_their_language(): void
    {
        $this->assertSame('ru', $this->admin('ru')->preferredLocale());
        $this->assertSame('hy', $this->admin('hy')->preferredLocale());
        $this->assertNull($this->admin(null)->preferredLocale());
        $this->assertNull($this->admin('xx')->preferredLocale());
    }
}
