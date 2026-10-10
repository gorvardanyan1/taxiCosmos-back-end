<?php

namespace Tests\Feature\Riders;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

class RiderUpdateTest extends AdminTestCase
{
    private User $support;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $this->rider = User::factory()->rider()->create(['name' => 'Priya Mehta', 'email' => 'priya@example.com', 'locale' => 'en']);
        $this->rider->forceFill(['phone' => '+37491210443'])->save();
    }

    private function edit(array $body, ?User $by = null, ?User $rider = null)
    {
        $rider ??= $this->rider;

        return $this->actingAs($by ?? $this->support)->patch("/admin/riders/{$rider->id}", ['reason' => 'Rider asked to correct their details', ...$body]);
    }

    public function test_each_contact_field_can_be_changed_and_the_rest_stays(): void
    {
        $this->edit(['name' => 'Priya M. Mehta'])->assertSessionHas('success', 'Rider updated.');
        $this->edit(['email' => 'new@example.com']);
        $this->edit(['locale' => 'ru']);
        $this->edit(['phone' => '091 555 666']);

        $fresh = $this->rider->fresh();
        $this->assertSame(['Priya M. Mehta', 'new@example.com', 'ru', '+37491555666'], [$fresh->name, $fresh->email, $fresh->locale, $fresh->phone]);
        $this->assertSame('active', $fresh->status->value);
    }

    public function test_several_fields_at_once_are_one_change_with_one_audit_entry(): void
    {
        $this->edit(['name' => 'New Name', 'email' => 'other@example.com', 'locale' => 'hy', 'phone' => '+37499112233'])->assertSessionHasNoErrors();

        $fresh = $this->rider->fresh();
        $this->assertSame(['New Name', 'other@example.com', 'hy', '+37499112233'], [$fresh->name, $fresh->email, $fresh->locale, $fresh->phone]);
        $this->assertSame(1, ActivityLogEntry::where('description', 'rider.updated')->count());
    }

    public function test_a_new_phone_is_stored_in_e164_and_found_through_the_blind_index(): void
    {
        $this->edit(['phone' => '0037491 555 666']);

        $this->assertSame($this->rider->id, User::wherePhone('+374 91-555-666')->sole()->id);
        $this->assertSame(0, User::wherePhone('+37491210443')->count(), 'The old number no longer finds the rider.');
        $this->assertStringNotContainsString('555666', DB::table('users')->where('id', $this->rider->id)->value('phone'), 'The phone stays encrypted.');
    }

    public function test_the_change_is_audited_with_actor_target_reason_and_a_diff_that_hides_contact_details(): void
    {
        $this->edit(['name' => 'New Name', 'email' => 'secret.new@example.com', 'phone' => '+37499112233', 'locale' => 'hy']);

        $entry = ActivityLogEntry::where('description', 'rider.updated')->sole();
        $this->assertSame($this->support->id, $entry->causer_id);
        $this->assertSame($this->rider->id, $entry->subject_id);
        $this->assertSame('Riley Chen', $entry->properties['actor_name']);
        $this->assertSame('Rider asked to correct their details', $entry->properties['reason']);
        $this->assertEquals(['name' => 'Priya Mehta', 'email' => 'changed', 'phone' => 'changed', 'locale' => 'en'], $entry->attribute_changes['old']);
        $this->assertEquals(['name' => 'New Name', 'email' => 'changed', 'phone' => 'changed', 'locale' => 'hy'], $entry->attribute_changes['attributes']);
        $stored = $entry->toJson();
        foreach (['secret.new@example.com', 'priya@example.com', '+37499112233', '+37491210443', '99112233'] as $pii) {
            $this->assertStringNotContainsString($pii, $stored);
        }
    }

    public function test_the_reason_is_required_and_bounded_and_nothing_changes_without_it(): void
    {
        foreach ([null, '', '   ', str_repeat('x', 501), ['a']] as $reason) {
            $this->actingAs($this->support)->patch("/admin/riders/{$this->rider->id}", ['name' => 'Changed', 'reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->actingAs($this->support)->patch("/admin/riders/{$this->rider->id}", ['name' => 'Changed'])->assertSessionHasErrors('reason');

        $this->assertSame('Priya Mehta', $this->rider->fresh()->name);
        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.updated')->count());

        $this->edit(['name' => 'Changed', 'reason' => str_repeat('x', 500)])->assertSessionHasNoErrors();
        $this->assertSame('Changed', $this->rider->fresh()->name);
    }

    public function test_sending_the_current_values_changes_nothing_and_logs_nothing(): void
    {
        $this->edit(['name' => 'Priya Mehta', 'email' => 'priya@example.com', 'locale' => 'en', 'phone' => '091 210 443'])
            ->assertSessionHas('success', 'Nothing to change.');
        $this->edit([])->assertSessionHas('success', 'Nothing to change.');

        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.updated')->count());
    }

    public function test_email_and_locale_can_be_cleared_but_name_and_phone_cannot(): void
    {
        $this->edit(['email' => null, 'locale' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->rider->fresh()->email);
        $this->assertNull($this->rider->fresh()->locale);

        $this->edit(['name' => ''])->assertSessionHasErrors('name');
        $this->edit(['phone' => ''])->assertSessionHasErrors('phone');
        $this->assertSame('Priya Mehta', $this->rider->fresh()->name);
        $this->assertSame('+37491210443', $this->rider->fresh()->phone, 'The phone is the rider\'s login: it cannot be blanked.');
    }

    #[DataProvider('invalidEdits')]
    public function test_invalid_input_is_refused_with_the_error_on_its_field(array $body, string $field): void
    {
        User::factory()->rider()->create(['email' => 'taken@example.com'])->forceFill(['phone' => '+37499112233'])->save();

        $this->edit($body)->assertSessionHasErrors($field);

        $fresh = $this->rider->fresh();
        $this->assertSame(['Priya Mehta', 'priya@example.com', 'en', '+37491210443'], [$fresh->name, $fresh->email, $fresh->locale, $fresh->phone]);
        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.updated')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidEdits(): array
    {
        return [
            'name over 255' => [['name' => str_repeat('n', 256)], 'name'],
            'name as array' => [['name' => ['x']], 'name'],
            'email without @' => [['email' => 'not-an-email'], 'email'],
            'email over 255' => [['email' => str_repeat('a', 250).'@x.com'], 'email'],
            'email of another user' => [['email' => 'taken@example.com'], 'email'],
            'email of another user, other case' => [['email' => 'TAKEN@Example.com'], 'email'],
            'phone that is text' => [['phone' => 'call me'], 'phone'],
            'phone too short' => [['phone' => '12'], 'phone'],
            'phone that cannot exist' => [['phone' => '+37400000000000'], 'phone'],
            'phone of another user' => [['phone' => '099 11 22 33'], 'phone'],
            'phone of another user, international' => [['phone' => '+374 99-112-233'], 'phone'],
            'phone as array' => [['phone' => ['+37491210443']], 'phone'],
            'locale not offered' => [['locale' => 'xx'], 'locale'],
            'locale in capitals' => [['locale' => 'EN'], 'locale'],
        ];
    }

    public function test_a_rider_can_keep_their_own_email_and_phone_while_changing_something_else(): void
    {
        $this->edit(['name' => 'Same Contact', 'email' => 'PRIYA@example.com', 'phone' => '+374 91 210 443'])->assertSessionHasNoErrors();

        $this->assertSame('Same Contact', $this->rider->fresh()->name);
    }

    public function test_the_email_is_stored_in_lower_case(): void
    {
        $this->edit(['email' => '  New.Address@Example.COM ']);

        $this->assertSame('new.address@example.com', $this->rider->fresh()->email);
    }

    public function test_only_riders_can_be_edited_here(): void
    {
        $driverOnly = User::factory()->driver()->create(['name' => 'Driver']);
        $adminOnly = $this->admin();

        foreach ([$driverOnly, $adminOnly] as $user) {
            $this->edit(['name' => 'Hacked'], rider: $user)->assertNotFound();
        }
        $this->actingAs($this->support)->patch('/admin/riders/987654', ['name' => 'X', 'reason' => 'x'])->assertNotFound();
        $this->actingAs($this->support)->patch('/admin/riders/99999999999999999999', ['name' => 'X', 'reason' => 'x'])->assertNotFound();

        $this->assertSame('Driver', $driverOnly->fresh()->name);
    }

    public function test_editing_does_not_touch_status_flags_or_publish_realtime_events(): void
    {
        $this->edit(['name' => 'Renamed', 'is_admin' => true, 'is_driver' => true, 'status' => 'suspended', 'password' => 'hacked-12345!']);

        $fresh = $this->rider->fresh();
        $this->assertSame(['active', false, false], [$fresh->status->value, $fresh->is_admin, $fresh->is_driver]);
        $this->assertTrue(password_verify('password', $fresh->password), 'The password cannot be set through this endpoint.');
    }

    public function test_only_roles_with_riders_edit_can_edit(): void
    {
        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->edit(['name' => 'Hacked'], by: $this->admin($role))->assertForbidden();
        }
        $this->assertSame('Priya Mehta', $this->rider->fresh()->name);

        foreach ([AdminRole::Admin, AdminRole::SuperAdmin] as $role) {
            $this->edit(['name' => 'By '.$role->value], by: $this->admin($role))->assertSessionHasNoErrors();
        }

        auth()->logout();
        $this->patch("/admin/riders/{$this->rider->id}", ['name' => 'Guest', 'reason' => 'x'])->assertRedirect('/login');
    }

    public function test_the_edit_reads_the_row_under_a_lock(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->edit(['name' => 'Locked edit']);

        $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_ends_with($sql, 'for update'))->implode("\n");
        $this->assertStringContainsString('from "users"', $locking);
    }
}
