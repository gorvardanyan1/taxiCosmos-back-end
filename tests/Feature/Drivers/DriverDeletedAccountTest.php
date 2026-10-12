<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

/**
 * A driver whose account was deleted keeps their records for review, but nobody can edit, suspend or
 * reactivate them: the presenter hides their personal data, so the write paths must not bring it back.
 */
class DriverDeletedAccountTest extends AdminTestCase
{
    private const MESSAGE = "This driver's account was deleted. It can no longer be changed.";

    private User $support;

    private DriverProfile $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->admin(AdminRole::Support);
        $this->driver = DriverProfile::factory()->for(User::factory()->driver()->withPhone('+37491440221')->create(['name' => 'Gor Hakobyan']), 'user')->create();
        $this->driver->user->delete();
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function writes(): array
    {
        return [
            'edit' => ['patch', '', ['name' => 'Revived Name', 'reason' => 'Fixing a typo']],
            'suspend' => ['post', '/suspend', ['reason' => 'Complaints']],
            'reactivate' => ['post', '/reactivate', ['reason' => 'Appeal accepted']],
        ];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('writes')]
    public function test_a_deleted_drivers_account_cannot_be_changed(string $method, string $path, array $body): void
    {
        $before = User::withTrashed()->find($this->driver->user_id)->getAttributes();

        $this->actingAs($this->support)->{$method.'Json'}('/admin/drivers/'.$this->driver->id.$path, $body)
            ->assertStatus(409)->assertJson(['message' => self::MESSAGE]);

        $this->assertSame($before, User::withTrashed()->find($this->driver->user_id)->getAttributes(), 'the account row is untouched');
        $this->assertSame(0, ActivityLogEntry::where('description', 'like', 'driver.%')->count(), 'nothing was audited');
    }

    public function test_the_message_is_shown_on_the_page_the_admin_came_from(): void
    {
        $this->actingAs($this->support)->from('/admin/drivers/'.$this->driver->id)->withHeaders(['X-Inertia' => 'true'])
            ->patch('/admin/drivers/'.$this->driver->id, ['name' => 'Revived Name', 'reason' => 'x'])
            ->assertRedirect('/admin/drivers/'.$this->driver->id)->assertSessionHas('error', self::MESSAGE);
    }

    public function test_the_page_shows_no_edit_suspend_or_reactivate_for_a_deleted_driver_but_keeps_the_other_actions(): void
    {
        $this->actingAs($this->support)->get('/admin/drivers/'.$this->driver->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('driver.name', 'Deleted account')
                ->where('driver.raw.name', null)
                ->where('actions.edit', null)
                ->where('actions.suspend', null)
                ->where('actions.reactivate', null)
                ->where('actions.approveDocument', fn ($url) => $url !== null));
    }

    public function test_an_active_drivers_page_still_offers_the_actions(): void
    {
        $active = DriverProfile::factory()->create();

        $this->actingAs($this->support)->get('/admin/drivers/'.$active->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('actions.edit', '/admin/drivers/'.$active->id)
                ->where('actions.suspend', '/admin/drivers/'.$active->id.'/suspend')
                ->where('actions.reactivate', '/admin/drivers/'.$active->id.'/reactivate'));
    }
}
