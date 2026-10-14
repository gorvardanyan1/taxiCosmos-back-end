<?php

namespace Tests\Feature\Account;

use App\Enums\AppClient;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3-T5: the rider's saved places. CRUD, owner-only, at most 20, coordinates validated.
 */
class SavedPlacesApiTest extends AccountTestCase
{
    /** @return array<string, mixed> */
    private function place(array $override = []): array
    {
        return array_merge(['label' => 'home', 'name' => 'Home', 'address' => '12 Abovyan St, Yerevan', 'lat' => 40.1792, 'lng' => 44.5133], $override);
    }

    public function test_a_place_is_created_with_its_position_and_listed(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);

        $created = $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertCreated()
            ->assertJsonPath('data.label', 'home')->assertJsonPath('data.name', 'Home')->assertJsonPath('data.address', '12 Abovyan St, Yerevan')
            ->assertJsonPath('data.lat', 40.1792)->assertJsonPath('data.lng', 44.5133)->json('data');

        $row = SavedPlace::query()->sole();
        $this->assertSame($rider->id, $row->user_id);
        $this->assertSame($created['id'], $row->id);
        $this->assertEqualsWithDelta([40.1792, 44.5133], [$row->location->latitude, $row->location->longitude], 1e-9);
        // The column really is PostGIS geography: longitude first.
        $stored = DB::selectOne('select ST_X(location::geometry) as lng, ST_Y(location::geometry) as lat, GeometryType(location::geometry) as type from saved_places');
        $this->assertSame('POINT', $stored->type);
        $this->assertEqualsWithDelta([44.5133, 40.1792], [$stored->lng, $stored->lat], 1e-9);

        $this->getJson('/api/v1/saved-places', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $created['id']);
    }

    public function test_a_place_can_be_read_changed_and_deleted(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        $id = $this->postJson('/api/v1/saved-places', $this->place(), $headers)->json('data.id');

        $this->getJson("/api/v1/saved-places/{$id}", $headers)->assertOk()->assertJsonPath('data.name', 'Home');

        $this->patchJson("/api/v1/saved-places/{$id}", ['label' => 'work', 'name' => 'Office', 'lat' => 40.2, 'lng' => 44.6], $headers)->assertOk()
            ->assertJsonPath('data.label', 'work')->assertJsonPath('data.name', 'Office')->assertJsonPath('data.lat', 40.2)->assertJsonPath('data.lng', 44.6)
            ->assertJsonPath('data.address', '12 Abovyan St, Yerevan');
        $this->assertSame('work', SavedPlace::query()->findOrFail($id)->label->value);

        $this->deleteJson("/api/v1/saved-places/{$id}", [], $headers)->assertNoContent();
        $this->assertSame(0, SavedPlace::query()->count());
        $this->getJson("/api/v1/saved-places/{$id}", $headers)->assertNotFound();
    }

    public function test_a_partial_change_keeps_the_rest_including_the_position(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        $id = $this->postJson('/api/v1/saved-places', $this->place(), $headers)->json('data.id');

        $this->patchJson("/api/v1/saved-places/{$id}", ['name' => 'Mum'], $headers)->assertOk()->assertJsonPath('data.lat', 40.1792)->assertJsonPath('data.lng', 44.5133);
    }

    public function test_the_twenty_first_place_is_refused_with_a_reason_code(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        SavedPlace::factory()->count(19)->create(['user_id' => $rider->id]);

        $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertCreated();
        $this->postJson('/api/v1/saved-places', $this->place(['name' => 'One too many']), $headers)->assertStatus(422)->assertJsonPath('code', 'saved_places_limit_reached');

        $this->assertSame(20, SavedPlace::query()->where('user_id', $rider->id)->count());
    }

    public function test_the_cap_is_per_user_and_deleting_frees_a_slot(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        SavedPlace::factory()->count(20)->create(['user_id' => $rider->id]);
        $other = User::factory()->rider()->withPhone('+37491000002')->create();

        $this->postJson('/api/v1/saved-places', $this->place(), $this->as($other))->assertCreated();
        $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertStatus(422);
        $this->deleteJson('/api/v1/saved-places/'.SavedPlace::query()->where('user_id', $rider->id)->value('id'), [], $headers)->assertNoContent();
        $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertCreated();
    }

    public function test_the_cap_follows_the_configuration(): void
    {
        config(['taxikosmos.saved_places.max' => 2]);
        $rider = $this->rider();
        $headers = $this->as($rider);

        $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertCreated();
        $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertCreated();
        $this->postJson('/api/v1/saved-places', $this->place(), $headers)->assertStatus(422)->assertJsonPath('code', 'saved_places_limit_reached');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'latitude above 90' => [['lat' => 90.0001], 'lat'],
            'latitude below -90' => [['lat' => -90.0001], 'lat'],
            'longitude above 180' => [['lng' => 180.0001], 'lng'],
            'longitude below -180' => [['lng' => -180.0001], 'lng'],
            'latitude not a number' => [['lat' => 'north'], 'lat'],
            'longitude not a number' => [['lng' => 'NaN'], 'lng'],
            'latitude missing' => [['lat' => null], 'lat'],
            'longitude missing' => [['lng' => null], 'lng'],
            'unknown label' => [['label' => 'gym'], 'label'],
            'label missing' => [['label' => null], 'label'],
            'name missing' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('n', 81)], 'name'],
            'address missing' => [['address' => ''], 'address'],
            'address too long' => [['address' => str_repeat('a', 256)], 'address'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalid')]
    public function test_invalid_input_is_refused_and_nothing_is_saved(array $override, string $field): void
    {
        $this->postJson('/api/v1/saved-places', $this->place($override), $this->as($this->rider()))->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertSame(0, SavedPlace::query()->count());
    }

    public function test_the_boundary_values_are_accepted(): void
    {
        $headers = $this->as($this->rider());

        foreach ([[90, 180], [-90, -180], [0, 0]] as [$lat, $lng]) {
            $this->postJson('/api/v1/saved-places', $this->place(['lat' => $lat, 'lng' => $lng, 'name' => str_repeat('n', 80), 'address' => str_repeat('a', 255)]), $headers)->assertCreated();
        }
    }

    public function test_a_change_with_only_one_coordinate_is_refused_and_a_bad_change_keeps_the_old_place(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        $id = $this->postJson('/api/v1/saved-places', $this->place(), $headers)->json('data.id');

        $this->patchJson("/api/v1/saved-places/{$id}", ['lat' => 41.0], $headers)->assertStatus(422)->assertJsonValidationErrors('lng');
        $this->patchJson("/api/v1/saved-places/{$id}", ['lat' => 95, 'lng' => 44.0, 'name' => 'Changed'], $headers)->assertStatus(422)->assertJsonValidationErrors('lat');

        $row = SavedPlace::query()->findOrFail($id);
        $this->assertSame('Home', $row->name);
        $this->assertEqualsWithDelta(40.1792, $row->location->latitude, 1e-9);
    }

    public function test_the_owner_cannot_be_chosen_in_the_request(): void
    {
        $rider = $this->rider();
        $other = User::factory()->rider()->withPhone('+37491000002')->create();

        $this->postJson('/api/v1/saved-places', $this->place(['user_id' => $other->id]), $this->as($rider))->assertCreated();

        $this->assertSame($rider->id, SavedPlace::query()->sole()->user_id);
    }

    public function test_another_riders_place_is_not_found_for_read_change_and_delete(): void
    {
        $rider = $this->rider();
        $other = User::factory()->rider()->withPhone('+37491000002')->create();
        $theirs = SavedPlace::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);
        $headers = $this->as($rider);

        $this->getJson("/api/v1/saved-places/{$theirs->id}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/saved-places/{$theirs->id}", ['name' => 'Mine now'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/saved-places/{$theirs->id}", [], $headers)->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->name);
        $this->getJson('/api/v1/saved-places', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_missing_or_malformed_id_is_not_found(): void
    {
        $headers = $this->as($this->rider());

        $this->getJson('/api/v1/saved-places/999999', $headers)->assertNotFound();
        $this->getJson('/api/v1/saved-places/abc', $headers)->assertNotFound();
    }

    public function test_the_list_holds_only_the_callers_places(): void
    {
        $rider = $this->rider();
        $other = User::factory()->rider()->withPhone('+37491000002')->create();
        SavedPlace::factory()->create(['user_id' => $rider->id, 'name' => 'Mine']);
        SavedPlace::factory()->count(3)->create(['user_id' => $other->id]);

        $names = $this->getJson('/api/v1/saved-places', $this->as($rider))->assertOk()->json('data.*.name');

        $this->assertSame(['Mine'], $names);
    }

    public function test_guests_and_the_driver_app_are_refused(): void
    {
        $this->getJson('/api/v1/saved-places')->assertUnauthorized();
        $this->postJson('/api/v1/saved-places', $this->place())->assertUnauthorized();

        $driver = User::factory()->driver()->withPhone('+37491770001')->create();
        $this->getJson('/api/v1/saved-places', $this->as($driver, AppClient::Driver))->assertForbidden();
        $this->postJson('/api/v1/saved-places', $this->place(), $this->as($driver, AppClient::Driver))->assertForbidden();
        $this->assertSame(0, SavedPlace::query()->count());
    }

    public function test_deleting_the_account_removes_the_places(): void
    {
        $rider = $this->rider();
        SavedPlace::factory()->count(2)->create(['user_id' => $rider->id]);

        $rider->forceDelete();

        $this->assertSame(0, SavedPlace::query()->count());
    }

    public function test_the_label_column_rejects_an_unknown_value_at_the_database(): void
    {
        $rider = $this->rider();

        $this->expectException(QueryException::class);

        DB::insert("insert into saved_places (user_id, label, name, address, location, created_at, updated_at) values (?, 'gym', 'x', 'y', ST_GeogFromText('POINT(44 40)'), now(), now())", [$rider->id]);
    }
}
