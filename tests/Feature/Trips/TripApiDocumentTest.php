<?php

namespace Tests\Feature\Trips;

use App\Enums\DriverAvailability;
use Carbon\CarbonImmutable;

/**
 * docs/trips-api.md is what the mobile team reads: every JSON example in it has exactly the fields the API really sends, at every level.
 */
class TripApiDocumentTest extends TripApiTestCase
{
    /**
     * @return list<mixed> the JSON examples of the document, in order
     */
    private function examples(): array
    {
        preg_match_all('/```json\n(.*?)\n```/s', (string) file_get_contents(base_path('docs/trips-api.md')), $blocks);

        return array_map(fn (string $json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $blocks[1]);
    }

    /**
     * The fields of a value, whatever their values: object keys (sorted), and for a list the fields of its first element.
     */
    private function shape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return 'value';
        }

        if (array_is_list($value)) {
            return $value === [] ? 'list' : ['list', $this->shape($value[0])];
        }

        ksort($value);

        return array_map(fn ($item) => $this->shape($item), $value);
    }

    public function test_the_examples_match_what_the_api_sends(): void
    {
        $this->travelTo(CarbonImmutable::now()->startOfMinute());
        [$trip, $profile, $driverToken] = $this->matched();
        $this->redis->raw(['GEOADD', config('realtime.geo.locations_key'), '44.51', '40.17', (string) $profile->user_id]);
        $this->assertSame(DriverAvailability::OnTrip, $profile->refresh()->availability);
        $profile->forceFill(['rating_avg' => '4.85', 'rating_count' => 12])->save();

        $riderTrip = $this->fetch("/api/v1/trips/{$trip->id}", $this->riderToken)->assertOk()->json();
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk();
        $driverTrip = $this->act("/api/v1/driver/trips/{$trip->id}/start", $driverToken)->assertOk()->json();
        $this->travel(10)->minutes();
        $this->act("/api/v1/driver/trips/{$trip->id}/complete", $driverToken)->assertOk();
        $receipt = $this->fetch("/api/v1/trips/{$trip->id}/receipt", $this->riderToken)->assertOk()->json();
        $earnings = $this->fetch('/api/v1/driver/earnings', $driverToken)->assertOk()->json();

        [$docRiderTrip, $docReceipt, $docDriverTrip, $docEarnings] = $this->examples();

        $this->assertSame($this->shape($riderTrip), $this->shape($docRiderTrip), 'GET /trips/{id}');
        $this->assertSame($this->shape($receipt), $this->shape($docReceipt), 'GET /trips/{id}/receipt');
        $this->assertSame($this->shape($driverTrip), $this->shape($docDriverTrip), 'GET /driver/trips/current');
        $this->assertSame($this->shape($earnings), $this->shape($docEarnings), 'GET /driver/earnings');
    }

    public function test_the_document_lists_four_examples(): void
    {
        $this->assertCount(4, $this->examples());
    }
}
