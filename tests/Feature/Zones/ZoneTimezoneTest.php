<?php

namespace Tests\Feature\Zones;

use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Each zone's timezone is used for 'today' in its reports and scheduled surge": reports (P13-T4,
 * P13-T9) and surge (P5-T4) ask the zone for its day instead of using the server clock.
 */
class ZoneTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function zone(string $timezone): Zone
    {
        return Zone::factory()->create(['timezone' => $timezone]);
    }

    public function test_today_follows_the_zones_clock_not_the_servers(): void
    {
        // 21:00 UTC on Oct 10 is already Oct 11 in Yerevan (UTC+4) and still Oct 10 in Los Angeles (UTC-7).
        $this->travelTo('2026-10-10 21:00:00');

        $this->assertSame('2026-10-11', $this->zone('Asia/Yerevan')->today());
        $this->assertSame('2026-10-10', $this->zone('America/Los_Angeles')->today());
        $this->assertSame('2026-10-10', $this->zone('UTC')->today());
        $this->assertSame('2026-10-11 01:00', $this->zone('Asia/Yerevan')->localNow()->format('Y-m-d H:i'));
    }

    public function test_the_day_changes_exactly_at_local_midnight(): void
    {
        $zone = $this->zone('Asia/Yerevan');

        $this->travelTo('2026-10-10 19:59:59'); // 23:59:59 local
        $this->assertSame('2026-10-10', $zone->today());

        $this->travelTo('2026-10-10 20:00:00'); // 00:00:00 local
        $this->assertSame('2026-10-11', $zone->today());
    }

    public function test_day_bounds_are_the_local_day_as_utc_instants(): void
    {
        $this->travelTo('2026-10-10 12:00:00');

        [$start, $end] = $this->zone('Asia/Yerevan')->dayBounds('2026-10-10');

        $this->assertSame('2026-10-09 20:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 20:00:00', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->timezoneName);
    }

    public function test_day_bounds_default_to_the_zones_today(): void
    {
        $this->travelTo('2026-10-10 21:00:00');

        [$start] = $this->zone('Asia/Yerevan')->dayBounds();

        $this->assertSame('2026-10-10 20:00:00', $start->format('Y-m-d H:i:s'), 'Local today is Oct 11, which starts at 20:00 UTC on Oct 10.');
    }

    public function test_a_daylight_saving_day_is_not_24_hours_long(): void
    {
        $newYork = $this->zone('America/New_York');

        [$springStart, $springEnd] = $newYork->dayBounds('2026-03-08'); // clocks go forward
        [$autumnStart, $autumnEnd] = $newYork->dayBounds('2026-11-01'); // clocks go back
        [$plainStart, $plainEnd] = $newYork->dayBounds('2026-06-15');

        $this->assertEquals(23, $springStart->diffInHours($springEnd));
        $this->assertEquals(25, $autumnStart->diffInHours($autumnEnd));
        $this->assertEquals(24, $plainStart->diffInHours($plainEnd));
        $this->assertSame('2026-03-08 05:00:00', $springStart->format('Y-m-d H:i:s'), 'Midnight EST is 05:00 UTC.');
        $this->assertSame('2026-03-09 04:00:00', $springEnd->format('Y-m-d H:i:s'), 'The next midnight is EDT: 04:00 UTC.');
    }

    public function test_consecutive_days_tile_without_gap_or_overlap(): void
    {
        $zone = $this->zone('Europe/Berlin');

        [, $endOfFirst] = $zone->dayBounds('2026-10-24');
        [$startOfSecond, $endOfSecond] = $zone->dayBounds('2026-10-25'); // clocks go back that night
        [$startOfThird] = $zone->dayBounds('2026-10-26');

        $this->assertTrue($endOfFirst->equalTo($startOfSecond));
        $this->assertTrue($endOfSecond->equalTo($startOfThird));
    }

    public function test_each_zone_uses_its_own_timezone(): void
    {
        $this->travelTo('2026-10-10 21:00:00');
        $a = $this->zone('Asia/Yerevan');
        $b = $this->zone('Pacific/Auckland'); // UTC+13 in October

        $this->assertSame('2026-10-11', $a->today());
        $this->assertSame('2026-10-11', $b->today());
        $this->assertNotSame($a->dayBounds()[0]->toIso8601String(), $b->dayBounds()[0]->toIso8601String());
    }
}
