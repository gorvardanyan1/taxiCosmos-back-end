<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Zone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

/**
 * PATCH /admin/drivers/{id}: contact and licence details, only the fields sent, reason required.
 */
class DriverUpdateTest extends AdminTestCase
{
    private User $support;

    private DriverProfile $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $this->driver = DriverProfile::factory()->for(User::factory()->driver()->withPhone('+37491440221')->create(['name' => 'Gor Hakobyan', 'email' => 'gor@example.com']), 'user')->create([
            'license_number' => 'AM-DL 4242', 'license_expiry' => '2028-05-01',
        ]);
    }

    private function update(array $body, ?User $as = null, ?DriverProfile $driver = null)
    {
        return $this->actingAs($as ?? $this->support)->patch('/admin/drivers/'.($driver ?? $this->driver)->id, $body);
    }

    public function test_changing_details_updates_the_user_and_the_driver_profile_and_audits_it_with_the_reason(): void
    {
        $zone = Zone::factory()->create();

        $this->update([
            'name' => 'Gor H.', 'email' => 'GOR.NEW@example.com', 'phone' => '091 440 222', 'license_number' => 'am-dl 5555',
            'license_expiry' => '2030-01-31', 'home_zone_id' => $zone->id, 'reason' => 'Driver renewed their licence',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Driver updated.');

        $fresh = $this->driver->fresh();
        $user = $fresh->user;
        $this->assertSame(['Gor H.', 'gor.new@example.com', '+37491440222'], [$user->name, $user->email, $user->phone]);
        $this->assertSame('AM-DL5555', $fresh->license_number, 'licences are stored normalised');
        $this->assertSame('2030-01-31', $fresh->license_expiry->toDateString());
        $this->assertSame($zone->id, $fresh->home_zone_id);
        $this->assertTrue(DriverProfile::query()->whereLicenseNumber('AM-DL5555')->whereKey($fresh->id)->exists(), 'the blind index follows the new licence');
        $this->assertFalse(DriverProfile::query()->whereLicenseNumber('AM-DL4242')->exists());

        $entry = ActivityLogEntry::where('description', 'driver.updated')->sole();
        $this->assertSame($this->support->id, $entry->causer_id);
        $this->assertSame($fresh->id, $entry->subject_id);
        $this->assertSame('Driver renewed their licence', $entry->properties['reason']);
        $this->assertSame('Gor Hakobyan', $entry->attribute_changes['old']['name']);
        $this->assertSame('Gor H.', $entry->attribute_changes['attributes']['name']);
        $this->assertSame('2028-05-01', $entry->attribute_changes['old']['license_expiry']);
        $this->assertSame('2030-01-31', $entry->attribute_changes['attributes']['license_expiry']);
    }

    public function test_sensitive_values_never_reach_the_audit_log(): void
    {
        $this->update(['license_number' => 'AM-DL 5555', 'phone' => '091 440 222', 'email' => 'new@example.com', 'reason' => 'Corrections'])->assertSessionHasNoErrors();

        $entry = ActivityLogEntry::where('description', 'driver.updated')->sole();
        $json = $entry->toJson();
        foreach (['AM-DL', '4242', '5555', '440221', '440222', 'gor@example.com', 'new@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} must not be in the audit entry");
        }
        $this->assertSame('changed', $entry->attribute_changes['attributes']['license_number']);
        $this->assertSame('changed', $entry->attribute_changes['attributes']['phone']);
    }

    public function test_only_the_fields_sent_change(): void
    {
        $this->update(['name' => 'Gor Only', 'reason' => 'Typo'])->assertSessionHasNoErrors();

        $fresh = $this->driver->fresh();
        $this->assertSame('Gor Only', $fresh->user->name);
        $this->assertSame(['+37491440221', 'gor@example.com', 'AM-DL4242', '2028-05-01'], [$fresh->user->phone, $fresh->user->email, $fresh->license_number, $fresh->license_expiry->toDateString()]);
        $entry = ActivityLogEntry::where('description', 'driver.updated')->sole();
        $this->assertSame(['name'], array_keys($entry->attribute_changes['attributes']));
    }

    public function test_sending_the_values_they_already_have_changes_nothing_and_writes_no_audit_entry(): void
    {
        $this->update(['name' => 'Gor Hakobyan', 'phone' => '091 440 221', 'license_number' => ' am-dl4242 ', 'license_expiry' => '2028-05-01', 'reason' => 'No-op'])
            ->assertSessionHas('success', 'Nothing to change.');

        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.updated')->count());
    }

    public function test_the_email_and_the_home_zone_can_be_cleared(): void
    {
        $this->driver->forceFill(['home_zone_id' => Zone::factory()->create()->id])->save();

        $this->update(['email' => null, 'home_zone_id' => null, 'reason' => 'Left out'])->assertSessionHasNoErrors();

        $this->assertNull($this->driver->fresh()->user->email);
        $this->assertNull($this->driver->fresh()->home_zone_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'no reason' => [['name' => 'X'], 'reason'],
            'blank reason' => [['name' => 'X', 'reason' => '   '], 'reason'],
            'reason too long' => [['name' => 'X', 'reason' => str_repeat('r', 501)], 'reason'],
            'empty name' => [['name' => '', 'reason' => 'r'], 'name'],
            'name too long' => [['name' => str_repeat('n', 256), 'reason' => 'r'], 'name'],
            'bad email' => [['email' => 'not-an-email', 'reason' => 'r'], 'email'],
            'bad phone' => [['phone' => 'abc', 'reason' => 'r'], 'phone'],
            'empty phone' => [['phone' => '', 'reason' => 'r'], 'phone'],
            'empty licence' => [['license_number' => '', 'reason' => 'r'], 'license_number'],
            'licence too long' => [['license_number' => str_repeat('L', 65), 'reason' => 'r'], 'license_number'],
            'expiry not a date' => [['license_expiry' => 'tomorrow', 'reason' => 'r'], 'license_expiry'],
            'expiry in the wrong format' => [['license_expiry' => '31/01/2030', 'reason' => 'r'], 'license_expiry'],
            'expiry absurd' => [['license_expiry' => '2300-01-01', 'reason' => 'r'], 'license_expiry'],
            'unknown zone' => [['home_zone_id' => 999999, 'reason' => 'r'], 'home_zone_id'],
            'zone not a number' => [['home_zone_id' => 'abc', 'reason' => 'r'], 'home_zone_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalid')]
    public function test_invalid_input_shows_an_error_on_the_field_and_changes_nothing(array $body, string $field): void
    {
        $snapshot = fn () => [$this->driver->fresh()->license_expiry->toDateString(), $this->driver->fresh()->home_zone_id, $this->driver->fresh()->user->name, $this->driver->fresh()->user->phone];
        $before = $snapshot();

        $this->update($body)->assertSessionHasErrors($field);

        $this->assertSame($before, $snapshot());
        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.updated')->count());
    }

    public function test_duplicates_are_refused_phone_email_and_licence_of_another_account(): void
    {
        $other = DriverProfile::factory()->for(User::factory()->driver()->withPhone('+37499000111')->create(['email' => 'taken@example.com']), 'user')->create(['license_number' => 'AM-DL 7777']);
        $deleted = User::factory()->rider()->withPhone('+37477123456')->create();
        $deleted->delete();

        $this->update(['phone' => '+37499000111', 'reason' => 'r'])->assertSessionHasErrors(['phone' => 'Another account already uses this phone number.']);
        $this->update(['phone' => '+37477123456', 'reason' => 'r'])->assertSessionHasErrors('phone');
        $this->update(['email' => 'TAKEN@example.com', 'reason' => 'r'])->assertSessionHasErrors('email');
        $this->update(['license_number' => ' am-dl7777 ', 'reason' => 'r'])->assertSessionHasErrors(['license_number' => 'Another driver already has this licence number.']);

        $this->assertSame('+37491440221', $this->driver->fresh()->user->phone);
        $this->assertSame($other->id, DriverProfile::query()->whereLicenseNumber('AM-DL7777')->value('id'));
    }

    public function test_a_driver_can_keep_their_own_phone_email_and_licence_when_saving_other_changes(): void
    {
        $this->update(['phone' => '+37491440221', 'email' => 'gor@example.com', 'license_number' => 'AM-DL4242', 'name' => 'Renamed', 'reason' => 'r'])->assertSessionHasNoErrors();
    }

    public function test_an_unknown_driver_is_a_404(): void
    {
        $this->actingAs($this->support)->patch('/admin/drivers/999999', ['name' => 'X', 'reason' => 'r'])->assertNotFound();
        $this->actingAs($this->support)->patch('/admin/drivers/abc', ['name' => 'X', 'reason' => 'r'])->assertNotFound();
    }

    public function test_the_licence_number_is_never_sent_to_the_browser(): void
    {
        $this->actingAs($this->support)->get('/admin/drivers/'.$this->driver->id)->assertInertia(fn ($page) => $page
            ->where('driver.license_last4', '4242')->where('driver.raw.license_expiry', '2028-05-01'));
        $this->assertStringNotContainsString('AM-DL', $this->actingAs($this->support)->get('/admin/drivers/'.$this->driver->id)->getContent());
    }

    // ---- who may ----

    public function test_roles_without_drivers_edit_get_403(): void
    {
        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->update(['name' => 'Hacked', 'reason' => 'r'], $this->admin($role))->assertForbidden();
        }

        $this->assertSame('Gor Hakobyan', $this->driver->fresh()->user->name);
        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.updated')->count());
    }

    public function test_admin_super_admin_and_support_may_edit_and_guests_are_sent_to_login(): void
    {
        foreach ([AdminRole::Admin, AdminRole::SuperAdmin, AdminRole::Support] as $i => $role) {
            $this->update(['name' => "Edited {$i}", 'reason' => 'r'], $this->admin($role))->assertSessionHasNoErrors();
        }
        $this->assertSame('Edited 2', $this->driver->fresh()->user->name);

        auth()->logout();
        $this->call('PATCH', '/admin/drivers/'.$this->driver->id, ['name' => 'Guest', 'reason' => 'r'])->assertRedirect('/login');
    }
}
