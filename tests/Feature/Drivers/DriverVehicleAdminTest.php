<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

/**
 * GET/POST /admin/drivers/{id}/vehicles, PATCH .../vehicles/{vehicleId}, POST .../vehicles/{vehicleId}/set-primary.
 */
class DriverVehicleAdminTest extends AdminTestCase
{
    private User $admin;

    private DriverProfile $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Support);
        $this->driver = DriverProfile::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'color' => 'White', 'plate_number' => '11 aa 111', 'vehicle_class' => 'comfort', ...$overrides];
    }

    private function add(array $overrides = [], ?User $as = null, ?DriverProfile $driver = null)
    {
        return $this->actingAs($as ?? $this->admin)->post('/admin/drivers/'.($driver ?? $this->driver)->id.'/vehicles', $this->payload($overrides));
    }

    // ---- the Vehicles tab ----

    public function test_the_vehicles_url_renders_the_driver_page_on_the_vehicles_tab(): void
    {
        Vehicle::factory()->primary()->active()->for($this->driver, 'driver')->create(['plate_number' => '22 BB 222']);

        $this->actingAs($this->admin)->get('/admin/drivers/'.$this->driver->id.'/vehicles')->assertInertia(fn ($page) => $page
            ->component('Drivers/Show')->where('tab', 'vehicles')->has('driver.vehicles', 1)->where('driver.vehicles.0.plate_number', '22 BB 222')
            ->where('actions.addVehicle', '/admin/drivers/'.$this->driver->id.'/vehicles')
            ->where('actions.updateVehicle', '/admin/drivers/'.$this->driver->id.'/vehicles/{id}')
            ->where('actions.setPrimaryVehicle', '/admin/drivers/'.$this->driver->id.'/vehicles/{id}/set-primary'));
        $this->actingAs($this->admin)->get('/admin/drivers/999999/vehicles')->assertNotFound();
    }

    // ---- add ----

    public function test_adding_a_vehicle_creates_an_active_primary_vehicle_when_the_driver_has_none(): void
    {
        $this->add()->assertSessionHasNoErrors()->assertSessionHas('success', 'Vehicle added.');

        $vehicle = Vehicle::query()->sole();
        $this->assertSame($this->driver->id, $vehicle->driver_id);
        $this->assertSame(['Toyota', 'Camry', 2020, 'White', '11AA111', 'comfort', 'active', true], [$vehicle->make, $vehicle->model, $vehicle->year, $vehicle->color, $vehicle->plate_number, $vehicle->vehicle_class->value, $vehicle->status->value, $vehicle->is_primary]);
    }

    public function test_a_second_vehicle_does_not_take_the_primary_flag_and_a_non_active_one_never_does(): void
    {
        $this->add(['plate_number' => 'AA1'])->assertSessionHasNoErrors();
        $this->add(['plate_number' => 'BB2'])->assertSessionHasNoErrors();
        $other = DriverProfile::factory()->create();
        $this->add(['plate_number' => 'CC3', 'status' => 'pending_review'], null, $other)->assertSessionHasNoErrors();

        $this->assertSame(['AA1'], Vehicle::query()->where('is_primary', true)->pluck('plate_number')->all());
        $this->assertFalse(Vehicle::query()->where('plate_number', 'CC3')->value('is_primary'), 'a vehicle awaiting review is not made primary');
        $this->assertSame('pending_review', Vehicle::query()->where('plate_number', 'CC3')->first()->status->value);
    }

    public function test_adding_is_audited_with_the_actor_and_the_new_values(): void
    {
        $this->add();

        $entry = ActivityLogEntry::where('description', 'driver.vehicle.added')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame(Vehicle::query()->value('id'), $entry->subject_id);
        $this->assertSame('11AA111', $entry->attribute_changes['attributes']['plate_number']);
        $this->assertSame(sprintf('D-%04d', $this->driver->id), $entry->properties['context']['driver']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'no make' => [['make' => ''], 'make'],
            'make too long' => [['make' => str_repeat('m', 65)], 'make'],
            'no model' => [['model' => ''], 'model'],
            'year as text' => [['year' => 'abc'], 'year'],
            'year too old' => [['year' => 1979], 'year'],
            'year in the far future' => [['year' => 2999], 'year'],
            'no color' => [['color' => ''], 'color'],
            'color too long' => [['color' => str_repeat('c', 33)], 'color'],
            'no plate' => [['plate_number' => ''], 'plate_number'],
            'plate too long' => [['plate_number' => str_repeat('P', 21)], 'plate_number'],
            'unknown class' => [['vehicle_class' => 'limousine'], 'vehicle_class'],
            'unknown status' => [['status' => 'lost'], 'status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalid')]
    public function test_invalid_input_shows_an_error_on_the_field_and_adds_nothing(array $overrides, string $field): void
    {
        $this->add($overrides)->assertSessionHasErrors($field);

        $this->assertSame(0, Vehicle::query()->count());
        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.vehicle.added')->count());
    }

    public function test_every_field_is_required_to_add(): void
    {
        $this->actingAs($this->admin)->post('/admin/drivers/'.$this->driver->id.'/vehicles', [])
            ->assertSessionHasErrors(['make', 'model', 'year', 'color', 'plate_number', 'vehicle_class']);
    }

    public function test_the_year_boundaries_are_accepted(): void
    {
        $this->add(['year' => 1980, 'plate_number' => 'OLD1'])->assertSessionHasNoErrors();
        $this->add(['year' => (int) now()->year + 1, 'plate_number' => 'NEW1'])->assertSessionHasNoErrors();

        $this->assertSame(2, Vehicle::query()->count());
    }

    public function test_a_plate_belongs_to_one_vehicle_whatever_its_spacing_or_case_but_a_removed_vehicles_plate_is_free(): void
    {
        $this->add(['plate_number' => '11 AA 111'])->assertSessionHasNoErrors();
        $another = DriverProfile::factory()->create();

        $this->add(['plate_number' => '11aa111'], null, $another)->assertSessionHasErrors(['plate_number' => 'Another vehicle already has this plate number.']);
        $this->assertSame(1, Vehicle::query()->count());

        Vehicle::query()->sole()->delete();
        $this->add(['plate_number' => '11 AA 111'], null, $another)->assertSessionHasNoErrors();
    }

    // ---- update ----

    private function vehicle(array $attributes = [], bool $primary = true): Vehicle
    {
        $factory = Vehicle::factory()->active()->for($this->driver, 'driver');

        return ($primary ? $factory->primary() : $factory)->create($attributes + ['plate_number' => 'ORIG1']);
    }

    private function change(Vehicle $vehicle, array $body, ?User $as = null, ?DriverProfile $driver = null)
    {
        return $this->actingAs($as ?? $this->admin)->patch('/admin/drivers/'.($driver ?? $this->driver)->id.'/vehicles/'.$vehicle->id, $body);
    }

    public function test_updating_changes_only_the_fields_sent_and_audits_old_and_new_values(): void
    {
        $vehicle = $this->vehicle(['make' => 'Kia', 'model' => 'Forte', 'color' => 'Blue', 'year' => 2018]);

        $this->change($vehicle, ['color' => 'Red', 'plate_number' => 'new 2'])->assertSessionHasNoErrors()->assertSessionHas('success', 'Vehicle updated.');

        $fresh = $vehicle->fresh();
        $this->assertSame(['Kia', 'Forte', 'Red', 2018, 'NEW2'], [$fresh->make, $fresh->model, $fresh->color, $fresh->year, $fresh->plate_number]);
        $entry = ActivityLogEntry::where('description', 'driver.vehicle.updated')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertEqualsCanonicalizing(['color' => 'Blue', 'plate_number' => 'ORIG1'], $entry->attribute_changes['old']);
        $this->assertEqualsCanonicalizing(['color' => 'Red', 'plate_number' => 'NEW2'], $entry->attribute_changes['attributes']);
    }

    public function test_sending_the_same_values_changes_nothing_and_writes_no_audit_entry(): void
    {
        $vehicle = $this->vehicle(['color' => 'Blue']);

        $this->change($vehicle, ['color' => 'Blue', 'plate_number' => 'orig1'])->assertSessionHas('success', 'Nothing to change.');

        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.vehicle.updated')->count());
    }

    public function test_a_vehicle_keeps_its_own_plate_when_saving_other_changes_but_cannot_take_anothers(): void
    {
        $first = $this->vehicle(['plate_number' => 'AAA1']);
        $second = $this->vehicle(['plate_number' => 'BBB2'], false);

        $this->change($first, ['plate_number' => 'AAA1', 'color' => 'Pink'])->assertSessionHasNoErrors();
        $this->change($second, ['plate_number' => 'aaa 1'])->assertSessionHasErrors('plate_number');

        $this->assertSame('BBB2', $second->fresh()->plate_number);
    }

    public function test_deactivating_the_primary_vehicle_clears_the_primary_flag(): void
    {
        $vehicle = $this->vehicle();

        $this->change($vehicle, ['status' => 'inactive'])->assertSessionHasNoErrors();

        $fresh = $vehicle->fresh();
        $this->assertSame(['inactive', false], [$fresh->status->value, $fresh->is_primary]);
        $this->assertSame(0, Vehicle::query()->where('driver_id', $this->driver->id)->where('is_primary', true)->count());
        $entry = ActivityLogEntry::where('description', 'driver.vehicle.updated')->sole();
        $this->assertSame(['status' => 'inactive', 'is_primary' => false], $entry->attribute_changes['attributes']);
    }

    public function test_the_primary_flag_cannot_be_set_through_the_update(): void
    {
        $primary = $this->vehicle();
        $other = $this->vehicle(['plate_number' => 'ZZZ9'], false);

        $this->change($other, ['is_primary' => true, 'color' => 'Pink'])->assertSessionHasNoErrors();

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse($other->fresh()->is_primary);
    }

    public function test_another_drivers_vehicle_and_a_removed_vehicle_are_a_404(): void
    {
        $foreign = Vehicle::factory()->active()->for(DriverProfile::factory()->create(), 'driver')->create();
        $removed = $this->vehicle(['plate_number' => 'GONE1'], false);
        $removed->delete();

        $this->change($foreign, ['color' => 'Hacked'])->assertNotFound();
        $this->change($removed, ['color' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/drivers/'.$this->driver->id.'/vehicles/'.$foreign->id.'/set-primary')->assertNotFound();

        $this->assertNotSame('Hacked', $foreign->fresh()->color);
    }

    // ---- set primary ----

    public function test_set_primary_moves_the_flag_and_audits_the_previous_vehicle(): void
    {
        $old = $this->vehicle();
        $new = $this->vehicle(['plate_number' => 'NEW1'], false);

        $this->actingAs($this->admin)->post('/admin/drivers/'.$this->driver->id.'/vehicles/'.$new->id.'/set-primary')
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Primary vehicle changed.');

        $this->assertFalse($old->fresh()->is_primary);
        $this->assertTrue($new->fresh()->is_primary);
        $this->assertSame(1, Vehicle::query()->where('driver_id', $this->driver->id)->where('is_primary', true)->count());
        $entry = ActivityLogEntry::where('description', 'driver.vehicle.primary_set')->sole();
        $this->assertSame(['primary_vehicle_id' => $old->id], $entry->attribute_changes['old']);
        $this->assertSame(['primary_vehicle_id' => $new->id], $entry->attribute_changes['attributes']);
    }

    public function test_only_an_active_vehicle_can_become_primary_and_the_primary_cannot_be_set_again(): void
    {
        $primary = $this->vehicle();
        $pending = Vehicle::factory()->for($this->driver, 'driver')->create(['plate_number' => 'PEND1']);

        $this->actingAs($this->admin)->postJson('/admin/drivers/'.$this->driver->id.'/vehicles/'.$pending->id.'/set-primary')
            ->assertStatus(409)->assertJson(['message' => 'Only an active vehicle can be the primary vehicle.']);
        $this->actingAs($this->admin)->postJson('/admin/drivers/'.$this->driver->id.'/vehicles/'.$primary->id.'/set-primary')
            ->assertStatus(409)->assertJson(['message' => 'This vehicle is already the primary vehicle.']);

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.vehicle.primary_set')->count());
    }

    // ---- who may ----

    public function test_roles_without_drivers_edit_get_403_on_every_vehicle_write(): void
    {
        $vehicle = $this->vehicle();
        $other = $this->vehicle(['plate_number' => 'OTH1'], false);

        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $user = $this->admin($role);
            $this->add([], $user)->assertForbidden();
            $this->change($vehicle, ['color' => 'Hacked'], $user)->assertForbidden();
            $this->actingAs($user)->post('/admin/drivers/'.$this->driver->id.'/vehicles/'.$other->id.'/set-primary')->assertForbidden();
        }

        $this->assertSame(2, Vehicle::query()->count());
        $this->assertNotSame('Hacked', $vehicle->fresh()->color);
        $this->assertTrue($vehicle->fresh()->is_primary);
        $this->assertSame(0, ActivityLogEntry::where('description', 'like', 'driver.vehicle.%')->count());
    }

    public function test_those_roles_still_see_the_vehicles_but_without_the_write_actions(): void
    {
        $this->vehicle();

        $this->actingAs($this->admin(AdminRole::Finance))->get('/admin/drivers/'.$this->driver->id.'/vehicles')->assertInertia(fn ($page) => $page
            ->has('driver.vehicles', 1)->where('actions.addVehicle', null)->where('actions.updateVehicle', null)->where('actions.setPrimaryVehicle', null));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->call('POST', '/admin/drivers/'.$this->driver->id.'/vehicles', $this->payload())->assertRedirect('/login');
        $this->assertSame(0, Vehicle::query()->count());
    }
}
