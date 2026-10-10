<?php

namespace Tests\Feature\DriverDocuments;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AdminFixtures;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Regression for the QA failure "Documents tab of a real driver is blank": a driver that exists
 * only in the database must reach the page with every prop the page reads (name, code, phone,
 * vehicle, earnings, ...), and with nothing borrowed from the fixture drivers.
 */
class DriverDetailPageTest extends DriverDocumentTestCase
{
    private function props(DriverProfile $driver, string $tab = 'documents'): array
    {
        $response = $this->actingAs($this->admin())->get("/admin/drivers/{$driver->id}?tab={$tab}")->assertOk();

        return $response->viewData('page')['props']['driver'];
    }

    public function test_a_database_driver_has_exactly_the_props_the_page_reads(): void
    {
        $driver = DriverProfile::factory()->create();
        $fixture = app(AdminFixtures::class)->get('drivers');
        $fixtureProps = [...$fixture['rows'][0], ...$fixture['detail']];

        $props = $this->props($driver);

        $this->assertEqualsCanonicalizing(array_keys($fixtureProps), array_keys($props), 'Real and fixture driver props must have the same shape.');
        $this->assertEqualsCanonicalizing(array_keys($fixtureProps['earnings_detail']), array_keys($props['earnings_detail']));
        $this->assertEqualsCanonicalizing(array_keys($fixtureProps['earnings']), array_keys($props['earnings']));
    }

    public function test_the_header_comes_from_the_driver_and_their_primary_vehicle(): void
    {
        $user = User::factory()->driver()->withPhone('+37491440221')->create(['name' => 'Gor Hakobyan']);
        $driver = DriverProfile::factory()->for($user, 'user')->create(['rating_avg' => 4.5]);
        Vehicle::factory()->for($driver, 'driver')->create(['make' => 'Kia', 'model' => 'Forte', 'year' => 2022, 'plate_number' => '11 AA 111']);
        Vehicle::factory()->primary()->for($driver, 'driver')->create(['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'plate_number' => '22 BB 222']);

        $this->actingAs($this->admin())->get("/admin/drivers/{$driver->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('driver.id', $driver->id)
                ->where('driver.name', 'Gor Hakobyan')
                ->where('driver.phone', '+37491440221')
                ->where('driver.code', sprintf('D-%04d', $driver->id))
                ->where('driver.vehicle', ['label' => 'Toyota Camry', 'year' => 2020])
                ->where('driver.rating', '4.50')
                ->where('driver.verification_status', 'pending')
                ->has('driver.vehicles', 2)
                ->where('driver.vehicles.0.plate_number', '11 AA 111')
                ->where('driver.vehicles.0.is_primary', false)
                ->where('driver.vehicles.1.plate_number', '22 BB 222')
                ->where('driver.vehicles.1.is_primary', true));
    }

    public function test_a_driver_without_vehicle_phone_or_rating_still_has_every_field(): void
    {
        $driver = DriverProfile::factory()->create();

        $props = $this->props($driver);

        $this->assertNull($props['vehicle']);
        $this->assertNull($props['rating']);
        $this->assertSame([], $props['vehicles']);
        $this->assertSame('—', $props['phone']);
        $this->assertNotSame('', $props['name']);
    }

    public function test_nothing_from_the_fixture_drivers_leaks_into_a_real_driver(): void
    {
        $driver = DriverProfile::factory()->create();

        $props = $this->props($driver);

        $this->assertSame(0, $props['trips_count']);
        $this->assertSame(['amount' => 0, 'currency' => 'AMD'], $props['earnings']);
        $this->assertSame([], $props['trip_history']);
        $this->assertSame([], $props['bank_accounts']);
        $this->assertSame([], $props['earnings_detail']['ledger']);
        $this->assertSame(0, $props['earnings_detail']['balance']['amount']);
        $this->assertFalse($props['earnings_detail']['debt_limit_exceeded']);
        $this->assertSame([], $props['documents']);
    }

    public function test_bank_accounts_show_only_the_masked_number(): void
    {
        $driver = DriverProfile::factory()->create();
        DriverBankAccount::factory()->default()->for($driver, 'driver')->create(['bank_name' => 'Ameriabank', 'account_number' => 'AM12 3456 7890 4821']);

        $props = $this->props($driver, 'bank-accounts');

        $this->assertSame([['id' => $driver->bankAccounts()->value('id'), 'bank_name' => 'Ameriabank', 'account_last4' => '4821', 'is_default' => true]], $props['bank_accounts']);
        $this->assertStringNotContainsString('34567890', json_encode($props));
    }

    public function test_every_tab_renders_its_props_for_a_real_driver(): void
    {
        $driver = DriverProfile::factory()->create();

        foreach (['profile', 'documents', 'vehicles', 'earnings', 'trip-history', 'bank-accounts'] as $tab) {
            $this->actingAs($this->admin())->get("/admin/drivers/{$driver->id}?tab={$tab}")
                ->assertInertia(fn (Assert $page) => $page->component('Drivers/Show')->where('tab', $tab)->has('driver.name'));
        }
    }

    public function test_a_database_driver_wins_over_a_fixture_driver_with_the_same_id(): void
    {
        DriverProfile::factory()->count(3)->create();
        $driver = DriverProfile::query()->orderBy('id')->first();
        $driver->user->forceFill(['name' => 'Real Person'])->save();

        $this->actingAs($this->admin())->get("/admin/drivers/{$driver->id}")
            ->assertInertia(fn (Assert $page) => $page->where('driver.name', 'Real Person')->where('driver.vehicles', []));
    }
}
