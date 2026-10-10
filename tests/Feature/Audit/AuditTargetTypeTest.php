<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditTargetType;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTargetTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_type_maps_to_its_model_and_back(): void
    {
        foreach (AuditTargetType::cases() as $type) {
            $this->assertSame($type, AuditTargetType::forClass($type->modelClass()));
        }
        $this->assertNull(AuditTargetType::forClass('App\\Models\\Nothing'));
        $this->assertNull(AuditTargetType::forClass(null));
    }

    public function test_a_model_resolves_to_its_type_and_a_readable_reference_without_personal_data(): void
    {
        $user = User::factory()->withPhone('+37491440221')->create(['name' => 'Gor Hakobyan']);
        $driver = DriverProfile::factory()->create();
        $document = DriverDocument::factory()->for($driver, 'driver')->create();
        $vehicle = Vehicle::factory()->for($driver, 'driver')->create();

        $this->assertSame(AuditTargetType::Driver, AuditTargetType::forModel($driver));
        $this->assertSame(AuditTargetType::DriverDocument, AuditTargetType::forModel($document));
        $this->assertSame(AuditTargetType::Vehicle, AuditTargetType::forModel($vehicle));
        $this->assertSame(AuditTargetType::User, AuditTargetType::forModel($user));
        $this->assertSame(sprintf('D-%04d', $driver->id), AuditTargetType::describe($driver));
        $this->assertSame(sprintf('D-%04d / Driver\'s license', $driver->id), AuditTargetType::describe($document));
        $this->assertSame("Vehicle #{$vehicle->id}", AuditTargetType::describe($vehicle));
        $this->assertSame("User #{$user->id}", AuditTargetType::describe($user));
        $this->assertStringNotContainsString('Gor', AuditTargetType::describe($user));
    }

    public function test_the_filter_values_are_stable(): void
    {
        $this->assertSame(['driver', 'driver_document', 'vehicle', 'user'], AuditTargetType::values());
    }
}
