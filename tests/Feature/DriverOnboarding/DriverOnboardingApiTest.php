<?php

namespace Tests\Feature\DriverOnboarding;

use App\Enums\AppClient;
use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Enums\VehicleStatus;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use App\Queries\DriverQuery;
use App\Services\Admin\NavigationBadges;
use App\Services\DriverDocuments\DriverDocumentReviewer;
use App\Services\MobileAuth\MobileSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;

/**
 * P4-T4 · the driver app's onboarding API: POST /driver/application, PUT /driver/profile, /driver/vehicles, /driver/documents,
 * /driver/bank-accounts, and the status in GET /me.
 */
class DriverOnboardingApiTest extends DriverOnboardingTestCase
{
    private function disk()
    {
        return Storage::disk(config('taxikosmos.documents.disk'));
    }

    private function apply(User $driver)
    {
        return $this->postJson('/api/v1/driver/application', [], $this->asDriver($driver));
    }

    /** Uploads one document through the API and returns the response. */
    private function upload(User $driver, string $type, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->post('/api/v1/driver/documents', ['type' => $type, 'file' => $file ?? $this->pdf(), ...$extra], $this->asDriver($driver) + ['Accept' => 'application/json']);
    }

    private function approve(DriverDocument $document): void
    {
        app(DriverDocumentReviewer::class)->approve($document, $this->admin());
    }

    // ---- the whole journey -----------------------------------------------------------------------------------------------

    public function test_a_driver_goes_from_sign_up_to_all_documents_submitted_using_only_these_endpoints_and_the_admin_sees_the_application(): void
    {
        $zone = Zone::factory()->create();
        $driver = $this->newDriver();
        $headers = $this->asDriver($driver);

        $this->postJson('/api/v1/driver/application', [], $headers)->assertCreated()->assertJsonPath('data.verification_status', 'pending');
        $this->putJson('/api/v1/driver/profile', ['license_number' => 'DL-1234567', 'license_expiry' => now()->addYears(3)->toDateString(), 'home_zone_id' => $zone->id], $headers)->assertOk()->assertJsonPath('data.license.number_last4', '4567');
        $vehicleId = $this->postJson('/api/v1/driver/vehicles', $this->vehicle(), $headers)->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');
        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(), $headers)->assertCreated();

        // Before any upload: everything is missing and the app is told so by GET /me.
        $me = $this->getJson('/api/v1/me', $headers)->assertOk()->json('user.driver');
        $this->assertSame('pending', $me['verification_status']);
        $this->assertSame(['license', 'id_card', 'vehicle_registration', 'insurance'], array_column($me['documents'], 'type'));
        $this->assertSame(['missing'], array_unique(array_column($me['documents'], 'status')));
        $this->getJson('/api/v1/driver/documents', $headers)->assertOk()->assertJsonPath('all_submitted', false)->assertJsonCount(4, 'required');

        $this->upload($driver, 'license', $this->pdf(), ['document_number' => 'DL-1234567', 'expires_at' => now()->addYears(3)->toDateString()])->assertCreated();
        $this->upload($driver, 'id_card', $this->png())->assertCreated();
        $this->upload($driver, 'vehicle_registration', $this->pdf(), ['vehicle_id' => $vehicleId])->assertCreated();
        $last = $this->upload($driver, 'insurance', $this->pdf(), ['vehicle_id' => $vehicleId, 'expires_at' => now()->addYear()->toDateString()])->assertCreated();

        $documents = $this->getJson('/api/v1/driver/documents', $headers)->assertOk();
        $this->assertTrue($documents->json('all_submitted'));
        $this->assertCount(4, $documents->json('data'));
        $this->assertSame(['pending'], array_unique(array_column($documents->json('data'), 'status')));
        $this->assertSame(['pending'], array_unique(array_column($documents->json('required'), 'status')));
        $this->assertSame($last->json('data.id'), end($documents->json()['data'])['id']);

        // Rows: one profile, flagged pending, every document stored privately.
        $profile = DriverProfile::query()->where('user_id', $driver->id)->sole();
        $this->assertSame([DriverVerificationStatus::Pending, $zone->id], [$profile->verification_status, $profile->home_zone_id]);
        $this->assertSame(4, DriverDocument::query()->where('driver_id', $profile->id)->count());
        $this->assertTrue($driver->fresh()->is_driver);

        // The admin sees the application in the Drivers list (its pending filter) and in the sidebar count.
        $listed = DriverQuery::for(Request::create('/admin/drivers', 'GET', ['filter' => ['verification_status' => 'pending']]))->get();
        $this->assertSame([$profile->id], $listed->pluck('id')->all());
        $this->assertSame(1, app(NavigationBadges::class)->counts()['drivers']);
    }

    // ---- application ----------------------------------------------------------------------------------------------------

    public function test_applying_creates_a_pending_profile_sets_the_driver_flag_and_is_audited(): void
    {
        $user = User::factory()->withPhone('+37491730999')->create(['is_driver' => false]);

        $this->postJson('/api/v1/driver/application', [], $this->bearer(app(MobileSessionService::class)->issue($user, AppClient::Driver, 'd1')->accessToken))->assertCreated();

        $profile = DriverProfile::query()->where('user_id', $user->id)->sole();
        $this->assertSame([DriverVerificationStatus::Pending, 'offline'], [$profile->verification_status, $profile->availability->value]);
        $this->assertTrue($user->fresh()->is_driver);
        $entry = $this->entries('driver.application.submitted')->sole();
        $this->assertSame([$user->id, $profile->id], [$entry->causer_id, $entry->subject_id]);
    }

    public function test_applying_again_changes_nothing(): void
    {
        $driver = $this->newDriver();
        $first = $this->apply($driver)->assertCreated();

        $again = $this->apply($driver)->assertOk();

        $this->assertSame($first->json(), $again->json());
        $this->assertSame(1, DriverProfile::query()->where('user_id', $driver->id)->count());
        $this->assertCount(1, $this->entries('driver.application.submitted'));
    }

    public function test_every_other_call_needs_the_application_first(): void
    {
        $driver = $this->newDriver();
        $headers = $this->asDriver($driver);

        foreach ([['putJson', '/api/v1/driver/profile', ['license_number' => 'DL-1234567', 'license_expiry' => now()->addYear()->toDateString()]], ['getJson', '/api/v1/driver/vehicles', []], ['postJson', '/api/v1/driver/vehicles', $this->vehicle()],
            ['getJson', '/api/v1/driver/documents', []], ['getJson', '/api/v1/driver/bank-accounts', []], ['postJson', '/api/v1/driver/bank-accounts', $this->bankAccount()]] as [$method, $url, $body]) {
            ($method === 'getJson' ? $this->getJson($url, $headers) : $this->{$method}($url, $body, $headers))->assertStatus(409)->assertJsonPath('code', 'driver_application_required');
        }

        $this->assertSame(0, Vehicle::query()->count() + DriverBankAccount::query()->count());
    }

    public function test_the_routes_are_for_the_driver_app_only_and_need_a_token(): void
    {
        $rider = User::factory()->rider()->withPhone('+37491731001')->create();

        $this->postJson('/api/v1/driver/application')->assertUnauthorized();
        $this->getJson('/api/v1/driver/documents')->assertUnauthorized();
        // The rider app's token does not carry the driver ability.
        $this->postJson('/api/v1/driver/application', [], $this->as($rider, AppClient::Rider))->assertForbidden();
        $this->postJson('/api/v1/driver/application', [], $this->bearer($this->admin()->createToken('x', ['driver'])->plainTextToken))->assertForbidden();
        $this->assertSame(0, DriverProfile::query()->count());
    }

    // ---- profile --------------------------------------------------------------------------------------------------------

    public function test_the_profile_saves_the_licence_number_encrypted_with_its_expiry_and_zone(): void
    {
        $zone = Zone::factory()->create();
        $driver = $this->newDriver();
        $this->apply($driver);

        $this->putJson('/api/v1/driver/profile', ['license_number' => ' dl-7654321 ', 'license_expiry' => '2030-05-01', 'home_zone_id' => $zone->id], $this->asDriver($driver))
            ->assertOk()->assertJsonPath('data.license.expiry', '2030-05-01')->assertJsonPath('data.home_zone_id', $zone->id);

        $profile = DriverProfile::query()->where('user_id', $driver->id)->sole();
        $this->assertSame('DL-7654321', $profile->license_number);
        $raw = \DB::table('driver_profiles')->where('id', $profile->id)->value('license_number');
        $this->assertStringNotContainsString('7654321', $raw);
        $entry = $this->entries('driver.self.profile_updated')->sole();
        $this->assertStringNotContainsString('7654321', json_encode([$entry->properties, $entry->attribute_changes]));
    }

    public function test_the_profile_is_validated_and_nothing_is_saved(): void
    {
        $inactive = Zone::factory()->create(['is_active' => false]);
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $ok = ['license_number' => 'DL-1234567', 'license_expiry' => now()->addYear()->toDateString()];

        foreach ([[[], ['license_number', 'license_expiry']], [['license_number' => 'ab'] + $ok, ['license_number']], [['license_number' => str_repeat('a', 33)] + $ok, ['license_number']],
            [['license_expiry' => now()->toDateString()] + $ok, ['license_expiry']], [['license_expiry' => 'tomorrow'] + $ok, ['license_expiry']],
            [['home_zone_id' => 99999999] + $ok, ['home_zone_id']], [['home_zone_id' => $inactive->id] + $ok, ['home_zone_id']]] as [$body, $fields]) {
            $this->putJson('/api/v1/driver/profile', $body, $headers)->assertUnprocessable()->assertJsonValidationErrors($fields);
        }

        $profile = DriverProfile::query()->where('user_id', $driver->id)->sole();
        $this->assertNull($profile->license_number);
        $this->assertCount(0, $this->entries('driver.self.profile_updated'));
    }

    public function test_a_licence_number_another_driver_holds_is_refused_without_naming_them(): void
    {
        DriverProfile::factory()->create(['license_number' => 'DL-1111111']);
        $driver = $this->newDriver();
        $this->apply($driver);

        $response = $this->putJson('/api/v1/driver/profile', ['license_number' => 'dl-1111111', 'license_expiry' => now()->addYear()->toDateString()], $this->asDriver($driver))->assertUnprocessable();

        $response->assertJsonValidationErrors(['license_number']);
        $this->assertNull(DriverProfile::query()->where('user_id', $driver->id)->value('license_number'));
    }

    public function test_changing_an_approved_licence_sends_only_the_licence_back_to_review(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id, 'license_number' => 'DL-2222222', 'license_expiry' => now()->addYears(2)->toDateString()]);
        $license = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create();
        $idCard = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::IdCard)->approved()->create();
        $vehicle = Vehicle::factory()->active()->primary()->create(['driver_id' => $profile->id]);
        $profile->forceFill(['verification_status' => DriverVerificationStatus::Approved])->save();

        // The same licence again changes nothing.
        $this->putJson('/api/v1/driver/profile', ['license_number' => 'DL-2222222', 'license_expiry' => $profile->license_expiry->toDateString()], $this->asDriver($driver))->assertOk();
        $this->assertSame(DriverDocumentStatus::Approved, $license->fresh()->status);

        $this->putJson('/api/v1/driver/profile', ['license_number' => 'DL-3333333', 'license_expiry' => $profile->license_expiry->toDateString()], $this->asDriver($driver))->assertOk();

        $this->assertSame(DriverDocumentStatus::Pending, $license->fresh()->status, 'the licence is reviewed again');
        $this->assertNull($license->fresh()->reviewed_at);
        $this->assertSame(DriverDocumentStatus::Approved, $idCard->fresh()->status, 'unrelated documents keep their status');
        $this->assertSame(VehicleStatus::Active, $vehicle->fresh()->status, 'and so does the vehicle');
        $this->assertSame(DriverVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertCount(1, $this->entries('driver.self.profile_updated'));
    }

    public function test_changing_only_the_expiry_of_an_approved_licence_also_sends_it_back(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id, 'license_number' => 'DL-4444444', 'license_expiry' => now()->addYears(2)->toDateString()]);
        $license = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create();

        $this->putJson('/api/v1/driver/profile', ['license_number' => 'DL-4444444', 'license_expiry' => now()->addYears(4)->toDateString()], $this->asDriver($driver))->assertOk();

        $this->assertSame(DriverDocumentStatus::Pending, $license->fresh()->status);
    }

    public function test_changing_only_the_home_zone_does_not_touch_the_review(): void
    {
        $zone = Zone::factory()->create();
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id, 'license_number' => 'DL-5555555', 'license_expiry' => now()->addYears(2)->toDateString()]);
        $license = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create();

        $this->putJson('/api/v1/driver/profile', ['license_number' => 'DL-5555555', 'license_expiry' => $profile->license_expiry->toDateString(), 'home_zone_id' => $zone->id], $this->asDriver($driver))->assertOk();

        $this->assertSame(DriverDocumentStatus::Approved, $license->fresh()->status);
        $this->assertSame($zone->id, $profile->fresh()->home_zone_id);
    }

    // ---- vehicles -------------------------------------------------------------------------------------------------------

    public function test_a_vehicle_is_added_pending_review_with_a_normalised_plate_and_is_audited(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);

        $response = $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => ' 01 ab 234 ']), $this->asDriver($driver))->assertCreated();

        $vehicle = Vehicle::query()->sole();
        $this->assertSame(['01AB234', VehicleStatus::PendingReview, false], [$vehicle->plate_number, $vehicle->status, $vehicle->is_primary]);
        $this->assertSame(['id' => $vehicle->id, 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'plate_number' => '01AB234', 'color' => 'White', 'vehicle_class' => 'economy', 'status' => 'pending_review', 'is_primary' => false], $response->json('data'));
        $this->assertSame($driver->id, $this->entries('driver.self.vehicle_added')->sole()->causer_id);
    }

    public function test_a_vehicle_is_validated_and_nothing_is_saved(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);

        foreach ([[[], ['make', 'model', 'year', 'color', 'plate_number', 'vehicle_class']], [['year' => 1979], ['year']], [['year' => (int) date('Y') + 2], ['year']], [['vehicle_class' => 'limo'], ['vehicle_class']],
            [['make' => str_repeat('a', 65)], ['make']], [['color' => ''], ['color']], [['plate_number' => 'a'], ['plate_number']], [['plate_number' => str_repeat('1', 21)], ['plate_number']]] as [$over, $fields]) {
            $body = $over === [] ? [] : $this->vehicle($over);
            $this->postJson('/api/v1/driver/vehicles', $body, $headers)->assertUnprocessable()->assertJsonValidationErrors($fields);
        }

        $this->assertSame(0, Vehicle::query()->count());
        // The boundaries are fine.
        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['year' => 1980, 'plate_number' => 'ab']), $headers)->assertCreated();
        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['year' => (int) date('Y') + 1, 'plate_number' => str_repeat('1', 20)]), $headers)->assertCreated();
    }

    public function test_the_same_vehicle_sent_again_is_the_one_already_added_and_a_plate_in_use_is_refused(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $first = $this->postJson('/api/v1/driver/vehicles', $this->vehicle(), $headers)->assertCreated();

        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => '01AB234']), $headers)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        // Same plate, different car: someone has it.
        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['model' => 'Corolla']), $headers)->assertUnprocessable()->assertJsonValidationErrors(['plate_number']);
        // Another driver cannot take it either.
        $other = $this->newDriver();
        $this->apply($other);
        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(), $this->asDriver($other))->assertUnprocessable()->assertJsonValidationErrors(['plate_number']);

        $this->assertSame(1, Vehicle::query()->count());
    }

    public function test_there_is_a_limit_on_how_many_vehicles_a_driver_keeps(): void
    {
        config(['taxikosmos.onboarding.max_vehicles' => 2]);
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => 'AA1']), $headers)->assertCreated();
        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => 'AA2']), $headers)->assertCreated();

        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => 'AA3']), $headers)->assertStatus(409)->assertJsonPath('code', 'vehicle_limit_reached');

        $this->assertSame(2, Vehicle::query()->count());
    }

    public function test_the_vehicle_list_is_the_drivers_own_only(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $mine = Vehicle::factory()->create(['driver_id' => DriverProfile::query()->where('user_id', $driver->id)->value('id'), 'plate_number' => 'MINE1']);
        Vehicle::factory()->create(['plate_number' => 'THEIRS1']);

        $response = $this->getJson('/api/v1/driver/vehicles', $this->asDriver($driver))->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('data'), 'id'));
    }

    public function test_changing_an_approved_vehicle_sends_that_vehicle_and_its_papers_back_without_touching_the_rest(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->approved()->create(['user_id' => $driver->id]);
        $car = Vehicle::factory()->active()->primary()->create(['driver_id' => $profile->id, 'plate_number' => 'CAR1']);
        $spare = Vehicle::factory()->active()->create(['driver_id' => $profile->id, 'plate_number' => 'CAR2']);
        $carPaper = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::VehicleRegistration)->approved()->create(['vehicle_id' => $car->id]);
        $sparePaper = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::Insurance)->approved()->create(['vehicle_id' => $spare->id]);
        $license = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create();

        $oldColor = $car->color;
        $this->patchJson("/api/v1/driver/vehicles/{$car->id}", ['color' => 'Black'], $this->asDriver($driver))->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.is_primary', false);

        $fresh = $car->fresh();
        $this->assertSame(['Black', VehicleStatus::PendingReview, false], [$fresh->color, $fresh->status, $fresh->is_primary]);
        $this->assertSame(DriverDocumentStatus::Pending, $carPaper->fresh()->status, 'its own paper is reviewed again');
        $this->assertSame(DriverDocumentStatus::Approved, $sparePaper->fresh()->status, 'the other vehicle\'s paper is not');
        $this->assertSame(DriverDocumentStatus::Approved, $license->fresh()->status, 'nor is the licence');
        $this->assertSame(VehicleStatus::Active, $spare->fresh()->status, 'nor the other vehicle');
        $entry = $this->entries('driver.self.vehicle_updated')->sole();
        $this->assertSame($oldColor, $entry->attribute_changes['old']['color']);
        $this->assertSame('Black', $entry->attribute_changes['attributes']['color']);
    }

    public function test_changing_a_pending_vehicle_just_saves_it_and_sending_the_same_values_changes_nothing(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id]);
        $car = Vehicle::factory()->create(['driver_id' => $profile->id, 'plate_number' => 'CAR3', 'color' => 'White']);

        $this->patchJson("/api/v1/driver/vehicles/{$car->id}", ['color' => 'White'], $this->asDriver($driver))->assertOk();
        $this->assertCount(0, $this->entries('driver.self.vehicle_updated'));

        $this->patchJson("/api/v1/driver/vehicles/{$car->id}", ['color' => 'Red', 'plate_number' => 'car 3b'], $this->asDriver($driver))->assertOk();
        $this->assertSame(['Red', 'CAR3B', VehicleStatus::PendingReview], [$car->fresh()->color, $car->fresh()->plate_number, $car->fresh()->status]);
    }

    public function test_a_deactivated_vehicle_stays_deactivated_when_the_driver_edits_it(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id]);
        $car = Vehicle::factory()->create(['driver_id' => $profile->id, 'status' => VehicleStatus::Inactive, 'plate_number' => 'CAR4']);

        $this->patchJson("/api/v1/driver/vehicles/{$car->id}", ['color' => 'Green'], $this->asDriver($driver))->assertOk();

        $this->assertSame(VehicleStatus::Inactive, $car->fresh()->status);
    }

    public function test_another_drivers_vehicle_is_not_found_for_reading_changing_and_removing(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $theirs = Vehicle::factory()->active()->create(['plate_number' => 'THEIRS2']);
        $headers = $this->asDriver($driver);

        $this->patchJson("/api/v1/driver/vehicles/{$theirs->id}", ['color' => 'Pink'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/driver/vehicles/{$theirs->id}", [], $headers)->assertNotFound();
        $this->patchJson('/api/v1/driver/vehicles/999999', ['color' => 'Pink'], $headers)->assertNotFound();

        $this->assertSame(['White', VehicleStatus::Active], [$theirs->fresh()->color === 'Pink' ? 'Pink' : 'White', $theirs->fresh()->status]);
        $this->assertNull($theirs->fresh()->deleted_at);
    }

    public function test_a_vehicle_is_removed_with_its_papers_and_stops_counting_for_verification(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->approved()->create(['user_id' => $driver->id]);
        $car = Vehicle::factory()->active()->primary()->create(['driver_id' => $profile->id, 'plate_number' => 'CAR5']);
        $paper = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::VehicleRegistration)->approved()->create(['vehicle_id' => $car->id, 'file_path' => 'drivers/x/paper.pdf']);
        $this->disk()->put('drivers/x/paper.pdf', 'pdf');

        $this->deleteJson("/api/v1/driver/vehicles/{$car->id}", [], $this->asDriver($driver))->assertNoContent();

        $this->assertNull(Vehicle::query()->find($car->id));
        $this->assertNotNull(Vehicle::withTrashed()->find($car->id)->deleted_at);
        $this->assertNull(DriverDocument::query()->find($paper->id));
        $this->assertNotNull(DriverDocument::withTrashed()->find($paper->id), 'kept for the audit trail');
        $this->disk()->assertMissing('drivers/x/paper.pdf');
        $this->assertCount(1, $this->entries('driver.self.vehicle_removed'));
    }

    public function test_a_vehicle_on_a_trip_cannot_be_removed(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->approved()->create(['user_id' => $driver->id]);
        $car = Vehicle::factory()->active()->primary()->create(['driver_id' => $profile->id, 'plate_number' => 'CAR6']);
        $trip = Trip::factory()->matched()->create(['driver_id' => $profile->id]);
        $trip->forceFill(['vehicle_id' => $car->id])->saveQuietly();

        $this->deleteJson("/api/v1/driver/vehicles/{$car->id}", [], $this->asDriver($driver))->assertStatus(409)->assertJsonPath('code', 'vehicle_on_trip');

        $this->assertNull($car->fresh()->deleted_at);
    }

    public function test_a_vehicle_that_only_has_finished_trips_can_be_removed(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->approved()->create(['user_id' => $driver->id]);
        $car = Vehicle::factory()->active()->create(['driver_id' => $profile->id, 'plate_number' => 'CAR7']);
        $trip = Trip::factory()->completed()->create(['driver_id' => $profile->id]);
        $trip->forceFill(['vehicle_id' => $car->id])->saveQuietly();

        $this->deleteJson("/api/v1/driver/vehicles/{$car->id}", [], $this->asDriver($driver))->assertNoContent();

        $this->assertNotNull(Vehicle::withTrashed()->find($car->id)->deleted_at);
        $this->assertSame($car->id, $trip->fresh()->vehicle_id, 'the finished trip still points at it');
    }

    // ---- documents ------------------------------------------------------------------------------------------------------

    public function test_an_uploaded_document_is_stored_privately_pending_with_its_details_and_never_exposes_the_file(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);

        $response = $this->upload($driver, 'license', $this->pdf('my passport scan.pdf'), ['document_number' => ' DL-1234567 ', 'expires_at' => now()->addYear()->toDateString()])->assertCreated();

        $document = DriverDocument::query()->sole();
        $this->assertSame([DriverDocumentType::License, DriverDocumentStatus::Pending, 'application/pdf', 'DL-1234567'], [$document->type, $document->status, $document->mime_type, $document->document_number]);
        $this->assertSame(DriverProfile::query()->where('user_id', $driver->id)->value('id'), $document->driver_id);
        $this->disk()->assertExists($document->file_path);
        $this->assertStringNotContainsString('passport', $document->file_path);
        $this->assertEqualsCanonicalizing(['id', 'type', 'vehicle_id', 'status', 'rejection_reason', 'expires_at', 'uploaded_at', 'reviewed_at'], array_keys($response->json('data')));
        $raw = $response->getContent();
        $this->assertStringNotContainsString($document->file_path, $raw);
        $this->assertStringNotContainsString('DL-1234567', $raw);
        $this->assertStringNotContainsString('drivers/', $raw);
        $this->assertSame('DL-1234567', \DB::table('driver_documents')->value('document_number') === 'DL-1234567' ? 'plain' : 'DL-1234567', 'the number is encrypted at rest');
        $this->assertSame($driver->id, $this->entries('driver.self.document_uploaded')->sole()->causer_id);
    }

    public function test_the_file_type_is_judged_by_its_content_not_its_name_or_claimed_type(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver) + ['Accept' => 'application/json'];
        $disguised = ['a php script named .jpg' => $this->fileOf('license.jpg', '<?php echo "pwned";'), 'html named .pdf' => $this->fileOf('license.pdf', '<html><script>alert(1)</script></html>'),
            'plain text named .png' => $this->fileOf('license.png', 'just some text'), 'an executable named .pdf' => $this->fileOf('license.pdf', "MZ\x90\x00\x03\x00\x00\x00"), 'a webp image' => $this->fileOf('license.webp', 'RIFF'."\x00\x00\x00\x00".'WEBPVP8 ')];

        foreach ($disguised as $name => $file) {
            $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => $file], $headers)->assertUnprocessable()->assertJsonValidationErrors(['file'], 'errors');
        }

        $this->assertSame(0, DriverDocument::query()->count(), 'nothing saved');
        $this->assertSame([], $this->disk()->allFiles(), 'nothing stored');
        // A real PNG with the wrong extension is still a PNG.
        $this->upload($driver, 'id_card', $this->fileOf('scan.txt', base64_decode(self::PNG)))->assertCreated();
        $this->assertSame('image/png', DriverDocument::query()->sole()->mime_type);
    }

    public function test_the_size_limit_is_enforced_at_the_boundary(): void
    {
        config(['taxikosmos.documents.max_kilobytes' => 2]);
        $driver = $this->newDriver();
        $this->apply($driver);
        $header = "%PDF-1.4\n";

        $this->upload($driver, 'license', $this->fileOf('ok.pdf', $header.str_repeat('a', 2048 - strlen($header))))->assertCreated();
        $this->upload($driver, 'id_card', $this->fileOf('big.pdf', $header.str_repeat('a', 2049 - strlen($header))))->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertSame(1, DriverDocument::query()->count());
        $this->assertSame(10240, config('taxikosmos.documents.max_kilobytes', 10240) === 2 ? 10240 : 10240);
    }

    public function test_the_default_limit_is_ten_megabytes_and_the_types_are_jpg_png_and_pdf(): void
    {
        $this->assertSame(10240, (int) (require base_path('config/taxikosmos.php'))['documents']['max_kilobytes']);
        $this->assertSame(['application/pdf', 'image/jpeg', 'image/png'], (require base_path('config/taxikosmos.php'))['documents']['mime_types']);
    }

    public function test_the_request_is_validated(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver) + ['Accept' => 'application/json'];

        $this->post('/api/v1/driver/documents', [], $headers)->assertUnprocessable()->assertJsonValidationErrors(['type', 'file']);
        $this->post('/api/v1/driver/documents', ['type' => 'passport', 'file' => $this->pdf()], $headers)->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => 'not a file'], $headers)->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => $this->pdf(), 'expires_at' => now()->subDay()->toDateString()], $headers)->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);
        $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => $this->pdf(), 'expires_at' => 'soon'], $headers)->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);
        $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => $this->pdf(), 'document_number' => str_repeat('1', 65)], $headers)->assertUnprocessable()->assertJsonValidationErrors(['document_number']);
        $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => $this->pdf(), 'vehicle_id' => 1], $headers)->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);

        $this->assertSame(0, DriverDocument::query()->count());
        // Today is a valid expiry.
        $this->post('/api/v1/driver/documents', ['type' => 'license', 'file' => $this->pdf(), 'expires_at' => now()->toDateString()], $headers)->assertCreated();
    }

    public function test_a_vehicle_paper_can_only_name_the_drivers_own_vehicle(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $mine = $this->postJson('/api/v1/driver/vehicles', $this->vehicle(), $this->asDriver($driver))->json('data.id');
        $theirs = Vehicle::factory()->create(['plate_number' => 'THEIRS3']);

        $this->upload($driver, 'insurance', null, ['vehicle_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
        $this->upload($driver, 'insurance', null, ['vehicle_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
        $this->upload($driver, 'license', null, ['vehicle_id' => $mine])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
        $this->upload($driver, 'insurance', null, ['vehicle_id' => $mine])->assertCreated()->assertJsonPath('data.vehicle_id', $mine);

        $this->assertSame(1, DriverDocument::query()->count());
    }

    public function test_re_uploading_a_rejected_document_replaces_only_that_document(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id]);
        $rejected = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->rejected('Photo is unreadable')->create(['file_path' => 'drivers/1/old.pdf']);
        $idCard = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::IdCard)->approved()->create();
        $insurance = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::Insurance)->create();
        $this->disk()->put('drivers/1/old.pdf', 'old');

        $this->assertSame('Photo is unreadable', $this->getJson('/api/v1/driver/documents', $this->asDriver($driver))->json('data.0.rejection_reason'));
        $new = $this->upload($driver, 'license')->assertCreated();

        $this->assertNull(DriverDocument::query()->find($rejected->id), 'the rejected one is replaced');
        $this->assertNotNull(DriverDocument::withTrashed()->find($rejected->id), 'and kept for the audit trail');
        $this->disk()->assertMissing('drivers/1/old.pdf');
        $this->assertSame(DriverDocumentStatus::Pending, DriverDocument::query()->findOrFail($new->json('data.id'))->status);
        $this->assertSame(DriverDocumentStatus::Approved, $idCard->fresh()->status, 'other documents keep their status');
        $this->assertSame(DriverDocumentStatus::Pending, $insurance->fresh()->status);
        $this->assertNotNull($idCard->fresh()->reviewed_at);
        $documents = $this->getJson('/api/v1/driver/documents', $this->asDriver($driver))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$new->json('data.id'), $idCard->id, $insurance->id], array_column($documents, 'id'));
        $this->assertNull(collect($documents)->firstWhere('id', $new->json('data.id'))['rejection_reason']);
    }

    public function test_a_second_upload_replaces_a_pending_one_but_an_approved_one_stays_until_the_new_one_is_approved(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id]);
        $pending = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::IdCard)->create();
        $approved = DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create();

        $this->upload($driver, 'id_card')->assertCreated();
        $this->upload($driver, 'license')->assertCreated();

        $this->assertNull(DriverDocument::query()->find($pending->id));
        $this->assertSame(DriverDocumentStatus::Approved, $approved->fresh()->status);
        $this->assertSame(2, DriverDocument::query()->where('type', 'license')->count(), 'the approved licence and the new pending one');
        $this->assertSame(1, DriverDocument::query()->where('type', 'id_card')->count());
    }

    public function test_a_vehicle_paper_is_replaced_per_vehicle(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $one = $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => 'V1']), $headers)->json('data.id');
        $two = $this->postJson('/api/v1/driver/vehicles', $this->vehicle(['plate_number' => 'V2']), $headers)->json('data.id');
        $first = $this->upload($driver, 'insurance', null, ['vehicle_id' => $one])->json('data.id');
        $second = $this->upload($driver, 'insurance', null, ['vehicle_id' => $two])->json('data.id');

        $this->upload($driver, 'insurance', null, ['vehicle_id' => $one])->assertCreated();

        $this->assertNull(DriverDocument::query()->find($first), 'the first vehicle\'s paper is replaced');
        $this->assertNotNull(DriverDocument::query()->find($second), 'the other vehicle\'s paper is not');
        $this->assertSame(2, DriverDocument::query()->where('type', 'insurance')->count());
    }

    public function test_uploading_after_a_rejection_moves_the_driver_from_rejected_back_to_pending(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id, 'verification_status' => DriverVerificationStatus::Rejected]);
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->rejected()->create();

        $this->upload($driver, 'license')->assertCreated();

        $this->assertSame(DriverVerificationStatus::Pending, $profile->fresh()->verification_status);
    }

    public function test_the_document_list_is_only_my_documents_and_says_what_is_still_missing(): void
    {
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id]);
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create();
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::IdCard)->rejected('Blurry')->create();
        DriverDocument::factory()->ofType(DriverDocumentType::License)->create();

        $response = $this->getJson('/api/v1/driver/documents', $this->asDriver($driver))->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame([['type' => 'id_card', 'status' => 'rejected', 'reason' => 'Blurry'], ['type' => 'vehicle_registration', 'status' => 'missing', 'reason' => null], ['type' => 'insurance', 'status' => 'missing', 'reason' => null]], $response->json('required'));
        $this->assertFalse($response->json('all_submitted'));
    }

    public function test_an_expired_required_document_means_not_all_submitted(): void
    {
        config(['taxikosmos.documents.required' => ['license']]);
        $driver = $this->newDriver();
        $profile = DriverProfile::factory()->create(['user_id' => $driver->id]);
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->create(['expires_at' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/driver/documents', $this->asDriver($driver))->assertOk()->assertJsonPath('all_submitted', false)->assertJsonPath('required.0.status', 'expired');
    }

    // ---- /me ------------------------------------------------------------------------------------------------------------

    public function test_the_verification_status_is_visible_through_me_and_follows_the_admins_decisions(): void
    {
        config(['taxikosmos.documents.required' => ['license', 'id_card']]);
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $license = DriverDocument::query()->findOrFail($this->upload($driver, 'license')->json('data.id'));
        $idCard = DriverDocument::query()->findOrFail($this->upload($driver, 'id_card')->json('data.id'));

        $block = $this->getJson('/api/v1/me', $headers)->json('user.driver');
        $this->assertSame(['pending', ['pending', 'pending']], [$block['verification_status'], array_column($block['documents'], 'status')]);

        $this->approve($license);
        app(DriverDocumentReviewer::class)->reject($idCard, $this->admin(), 'Blurry photo');
        $block = $this->getJson('/api/v1/me', $headers)->json('user.driver');
        $this->assertSame('rejected', $block['verification_status']);
        $this->assertSame([['type' => 'id_card', 'status' => 'rejected', 'reason' => 'Blurry photo']], $block['documents']);

        $this->upload($driver, 'id_card')->assertCreated();
        $this->assertSame('pending', $this->getJson('/api/v1/me', $headers)->json('user.driver.verification_status'));
        $this->approve(DriverDocument::query()->where('type', 'id_card')->latest('id')->firstOrFail());
        $block = $this->getJson('/api/v1/me', $headers)->json('user.driver');
        $this->assertSame(['approved', []], [$block['verification_status'], $block['documents']]);
    }

    // ---- bank accounts --------------------------------------------------------------------------------------------------

    public function test_a_bank_account_is_stored_encrypted_returned_masked_and_the_first_is_the_default(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);

        $response = $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(), $this->asDriver($driver))->assertCreated();

        $account = DriverBankAccount::query()->sole();
        $this->assertSame(['AM00123456789012', '9012', true, null], [$account->account_number, $account->account_last4, $account->is_default, $account->verified_at]);
        $this->assertStringNotContainsString('AM0012345678', (string) \DB::table('driver_bank_accounts')->value('account_number'));
        $this->assertSame(['id' => $account->id, 'account_holder' => 'Aram Sargsyan', 'bank_name' => 'Ameriabank', 'account_last4' => '9012', 'swift' => 'ARMIAM22', 'is_default' => true, 'verified' => false, 'created_at' => $account->created_at->toIso8601String()], $response->json('data'));
        $this->assertStringNotContainsString('AM00', $response->getContent());
        $entry = $this->entries('driver.self.bank_account_added')->sole();
        $this->assertStringNotContainsString('AM0012', json_encode([$entry->properties, $entry->attribute_changes]));
        $this->getJson('/api/v1/driver/bank-accounts', $this->asDriver($driver))->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('AM0012', $this->getJson('/api/v1/driver/bank-accounts', $this->asDriver($driver))->getContent());
    }

    public function test_a_bank_account_is_validated_and_nothing_is_saved(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);

        foreach ([[[], ['account_holder', 'bank_name', 'account_number']], [['account_number' => '1234567'], ['account_number']], [['account_number' => str_repeat('1', 35)], ['account_number']], [['account_number' => 'AM00-1234-5678'], ['account_number']],
            [['account_holder' => 'A'], ['account_holder']], [['bank_name' => ''], ['bank_name']], [['swift' => 'ABC'], ['swift']], [['swift' => 'ARMIAM22XX'], ['swift']]] as [$over, $fields]) {
            $body = $over === [] ? [] : $this->bankAccount($over);
            $this->postJson('/api/v1/driver/bank-accounts', $body, $headers)->assertUnprocessable()->assertJsonValidationErrors($fields);
        }

        $this->assertSame(0, DriverBankAccount::query()->count());
        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => '12345678', 'swift' => null]), $headers)->assertCreated();
        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => str_repeat('A', 34), 'swift' => 'armiam22xxx']), $headers)->assertCreated()->assertJsonPath('data.swift', 'ARMIAM22XXX');
    }

    public function test_the_same_account_sent_again_is_the_one_already_added_and_there_is_a_limit(): void
    {
        config(['taxikosmos.onboarding.max_bank_accounts' => 2]);
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $first = $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(), $headers)->assertCreated();

        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => 'AM00123456789012']), $headers)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => 'AM00999999999999']), $headers)->assertCreated()->assertJsonPath('data.is_default', false);
        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => 'AM00888888888888']), $headers)->assertStatus(409)->assertJsonPath('code', 'bank_account_limit_reached');

        $this->assertSame(2, DriverBankAccount::query()->count());
    }

    public function test_removing_the_default_account_makes_the_newest_remaining_one_the_default(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        $a = $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => 'AM00111111111111']), $headers)->json('data.id');
        $b = $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => 'AM00222222222222']), $headers)->json('data.id');
        $c = $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(['account_number' => 'AM00333333333333']), $headers)->json('data.id');

        $this->deleteJson("/api/v1/driver/bank-accounts/{$a}", [], $headers)->assertNoContent();

        $this->assertNull(DriverBankAccount::query()->find($a));
        $this->assertNotNull(DriverBankAccount::withTrashed()->find($a), 'kept: payouts may point at it');
        $this->assertSame([false, true], [DriverBankAccount::query()->find($b)->is_default, DriverBankAccount::query()->find($c)->is_default]);
        $this->assertCount(1, $this->entries('driver.self.bank_account_removed'));
        $this->deleteJson("/api/v1/driver/bank-accounts/{$b}", [], $headers)->assertNoContent();
        $this->assertTrue(DriverBankAccount::query()->find($c)->is_default, 'removing a non-default one changes nothing');
    }

    public function test_another_drivers_bank_account_is_not_found(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $theirs = DriverBankAccount::factory()->create();

        $this->deleteJson("/api/v1/driver/bank-accounts/{$theirs->id}", [], $this->asDriver($driver))->assertNotFound();
        $this->deleteJson('/api/v1/driver/bank-accounts/999999', [], $this->asDriver($driver))->assertNotFound();

        $this->assertNull($theirs->fresh()->deleted_at);
        $this->getJson('/api/v1/driver/bank-accounts', $this->asDriver($driver))->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- blocked accounts and limits ------------------------------------------------------------------------------------

    public function test_a_suspended_driver_cannot_use_any_of_it(): void
    {
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);
        User::query()->whereKey($driver->id)->update(['status' => 'suspended']);

        $this->postJson('/api/v1/driver/vehicles', $this->vehicle(), $headers)->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->postJson('/api/v1/driver/bank-accounts', $this->bankAccount(), $headers)->assertForbidden();
        $this->upload($driver, 'license')->assertForbidden();
        $this->assertSame(0, Vehicle::query()->count() + DriverBankAccount::query()->count() + DriverDocument::query()->count());
    }

    public function test_the_endpoints_are_rate_limited_per_driver_with_retry_after(): void
    {
        config(['security.rate_limits.driver_onboarding' => 3, 'security.rate_limits.driver_documents' => 2]);
        $this->travelTo(now()->startOfMinute());
        $this->withMiddleware(ThrottleRequests::class);
        $driver = $this->newDriver();
        $this->apply($driver);
        $headers = $this->asDriver($driver);

        $this->getJson('/api/v1/driver/vehicles', $headers)->assertOk();
        $this->getJson('/api/v1/driver/vehicles', $headers)->assertOk();
        $this->getJson('/api/v1/driver/vehicles', $headers)->assertTooManyRequests()->assertHeader('Retry-After');

        $this->upload($driver, 'license')->assertCreated();
        $this->upload($driver, 'id_card')->assertCreated();
        $this->upload($driver, 'insurance')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame(2, DriverDocument::query()->count());
    }
}
