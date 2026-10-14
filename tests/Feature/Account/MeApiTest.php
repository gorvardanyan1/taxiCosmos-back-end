<?php

namespace Tests\Feature\Account;

use App\Enums\AppClient;
use App\Enums\DriverAvailability;
use App\Enums\DriverDocumentType;
use App\Enums\UserStatus;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MobileAuth\MobileSessionService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3-T5: GET /api/v1/me is the one shape both apps read on start. The shapes are pinned by the JSON fixtures in
 * tests/Fixtures/api/me (the mobile team's copy): a rider, a pending driver, an approved driver, a suspended user.
 * Run with UPDATE_FIXTURES=1 to rewrite them after an intended change to the shape.
 */
class MeApiTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['taxikosmos.documents.required' => ['license', 'id_card', 'vehicle_registration']]);
    }

    /**
     * The body with the ids a test cannot know replaced by 1, for comparison with a fixture.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function normalised(array $body): array
    {
        $body['user']['id'] = 1;

        if (isset($body['user']['driver']['primary_vehicle'])) {
            $body['user']['driver']['primary_vehicle']['id'] = 1;
        }

        return $body;
    }

    private function assertMatchesFixture(string $name, array $body): void
    {
        $path = base_path("tests/Fixtures/api/me/{$name}.json");
        $actual = $this->normalised($body);

        if (getenv('UPDATE_FIXTURES') === '1') {
            file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        // Same keys in the same order, same values and types: the shape is the contract.
        $this->assertSame(
            json_encode(json_decode((string) file_get_contents($path), true)),
            json_encode($actual),
            "GET /api/v1/me no longer matches tests/Fixtures/api/me/{$name}.json",
        );
    }

    public function test_a_rider_gets_the_documented_shape(): void
    {
        $rider = $this->rider(['name' => 'Ani Hakobyan', 'email' => 'ani@example.com', 'locale' => 'hy', 'timezone' => 'Asia/Yerevan']);
        $rider->forceFill(['rider_rating_avg' => 4.85, 'rider_rating_count' => 12])->save();

        $response = $this->getJson('/api/v1/me', $this->as($rider))->assertOk();

        $this->assertMatchesFixture('rider', $response->json());
        $this->assertSame($rider->id, $response->json('user.id'));
    }

    public function test_a_pending_driver_sees_which_documents_are_missing_waiting_or_rejected(): void
    {
        $user = User::factory()->driver()->withPhone('+37491770001')->create(['name' => 'Davit Sargsyan', 'email' => 'davit@example.com', 'locale' => 'ru']);
        $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->create();
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::IdCard)->rejected('The photo is blurry')->create();
        Vehicle::factory()->for($profile, 'driver')->primary()->create(['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'plate_number' => '12 AB 345', 'color' => 'white']);

        $response = $this->getJson('/api/v1/me', $this->as($user, AppClient::Driver))->assertOk();

        $this->assertMatchesFixture('pending_driver', $response->json());
        $this->assertSame('pending', $response->json('user.driver.verification_status'));
    }

    public function test_an_approved_driver_has_no_outstanding_documents(): void
    {
        $user = User::factory()->driver()->withPhone('+37491770002')->create(['name' => 'Gor Petrosyan', 'email' => 'gor@example.com', 'locale' => 'en']);
        $profile = DriverProfile::factory()->approved()->create(['user_id' => $user->id, 'availability' => DriverAvailability::Online]);
        foreach ([DriverDocumentType::License, DriverDocumentType::IdCard, DriverDocumentType::VehicleRegistration] as $type) {
            DriverDocument::factory()->for($profile, 'driver')->ofType($type)->approved()->create();
        }
        Vehicle::factory()->for($profile, 'driver')->primary()->active()->create(['make' => 'Hyundai', 'model' => 'Elantra', 'year' => 2022, 'plate_number' => '77 CD 777', 'color' => 'black']);

        $response = $this->getJson('/api/v1/me', $this->as($user, AppClient::Driver))->assertOk();

        $this->assertMatchesFixture('approved_driver', $response->json());
        $this->assertSame([], $response->json('user.driver.documents'));
    }

    public function test_a_suspended_user_can_still_read_their_status(): void
    {
        $user = $this->rider(['name' => 'Suren Avetisyan', 'email' => 'suren@example.com', 'locale' => 'en']);
        $headers = $this->as($user);
        // The state a still-open session can be in when the account is blocked a moment after the token was issued.
        User::query()->whereKey($user->id)->update(['status' => UserStatus::Suspended->value]);

        $response = $this->getJson('/api/v1/me', $headers)->assertOk();

        $this->assertMatchesFixture('suspended', $response->json());
        $this->assertSame('suspended', $response->json('user.status'));
    }

    public function test_the_phone_is_masked_and_no_secret_is_in_the_answer(): void
    {
        $user = $this->rider();

        $body = $this->getJson('/api/v1/me', $this->as($user))->assertOk()->getContent();

        $this->assertStringContainsString('+374*****443', $body);
        $this->assertStringNotContainsString('37491210443', $body);
        foreach (['phone_hash', 'password', 'remember_token', 'two_factor', 'license_number'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_a_person_who_both_rides_and_drives_gets_both_blocks(): void
    {
        $user = User::factory()->rider()->driver()->withPhone('+37491770003')->create();
        DriverProfile::factory()->create(['user_id' => $user->id]);

        $json = $this->getJson('/api/v1/me', $this->as($user, AppClient::Driver))->assertOk()->json('user');

        $this->assertSame(['is_rider' => true, 'is_driver' => true], $json['modes']);
        $this->assertIsArray($json['driver']);
        $this->assertIsArray($json['rider']);
    }

    public function test_a_driver_who_applied_but_uploaded_nothing_is_pending_with_every_document_missing(): void
    {
        $user = User::factory()->driver()->withPhone('+37491770004')->create();

        $driver = $this->getJson('/api/v1/me', $this->as($user, AppClient::Driver))->assertOk()->json('user.driver');

        $this->assertSame('pending', $driver['verification_status']);
        $this->assertSame('offline', $driver['availability']);
        $this->assertSame(['license', 'id_card', 'vehicle_registration'], array_column($driver['documents'], 'type'));
        $this->assertSame(['missing'], array_unique(array_column($driver['documents'], 'status')));
        $this->assertNull($driver['primary_vehicle']);
    }

    public function test_an_expired_document_is_reported_as_expired(): void
    {
        $user = User::factory()->driver()->withPhone('+37491770005')->create();
        $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->approved()->expiring(now()->subDay()->toDateString())->create();

        $documents = collect($this->getJson('/api/v1/me', $this->as($user, AppClient::Driver))->assertOk()->json('user.driver.documents'))->keyBy('type');

        $this->assertSame('expired', $documents['license']['status']);
    }

    public function test_a_rejected_document_replaced_by_a_new_upload_is_pending_again(): void
    {
        $user = User::factory()->driver()->withPhone('+37491770006')->create();
        $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->rejected('Blurry')->create();
        DriverDocument::factory()->for($profile, 'driver')->ofType(DriverDocumentType::License)->create();

        $license = collect($this->getJson('/api/v1/me', $this->as($user, AppClient::Driver))->json('user.driver.documents'))->keyBy('type')['license'];

        $this->assertSame(['type' => 'license', 'status' => 'pending', 'reason' => null], $license);
    }

    public function test_another_drivers_documents_and_vehicle_never_appear(): void
    {
        $mine = User::factory()->driver()->withPhone('+37491770007')->create();
        DriverProfile::factory()->create(['user_id' => $mine->id]);
        $other = DriverProfile::factory()->create();
        Vehicle::factory()->for($other, 'driver')->primary()->create(['plate_number' => 'ZZ 999 ZZ']);
        DriverDocument::factory()->for($other, 'driver')->ofType(DriverDocumentType::License)->approved()->create();

        $response = $this->getJson('/api/v1/me', $this->as($mine, AppClient::Driver))->assertOk();

        $this->assertNull($response->json('user.driver.primary_vehicle'));
        $this->assertSame('missing', collect($response->json('user.driver.documents'))->keyBy('type')['license']['status']);
        $this->assertStringNotContainsString('ZZ 999 ZZ', $response->getContent());
    }

    public function test_a_session_that_still_needs_its_phone_can_read_me(): void
    {
        $user = User::factory()->rider()->create();
        $session = app(MobileSessionService::class)->issue($user, AppClient::Rider, 'phone-1');

        $json = $this->getJson('/api/v1/me', $this->bearer($session->accessToken))->assertOk()->json('user');

        $this->assertNull($json['phone']);
        $this->assertSame($user->id, $json['id']);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function refusedCallers(): array
    {
        return [
            'guest' => ['none', 'guest', 401],
            'junk token' => ['junk', 'junk', 401],
        ];
    }

    #[DataProvider('refusedCallers')]
    public function test_a_caller_without_a_valid_token_is_refused(string $kind, string $label, int $status): void
    {
        $headers = $kind === 'junk' ? $this->bearer('1|not-a-real-token') : [];

        $this->getJson('/api/v1/me', $headers)->assertStatus($status)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_an_admin_account_cannot_use_it(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $token = $admin->createToken('x', ['rider'])->plainTextToken;

        $this->getJson('/api/v1/me', $this->bearer($token))->assertForbidden()->assertJsonPath('code', 'admin_account');
    }

    public function test_the_sign_in_answer_carries_the_same_user_shape(): void
    {
        $body = $this->signIn('+37491210443');

        $me = $this->getJson('/api/v1/me', $this->bearer($body['access_token']))->assertOk()->json('user');

        $this->assertSame($me, $body['user']);
    }
}
