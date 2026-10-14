<?php

namespace Tests\Feature\DriverLocation;

use App\Enums\DriverAvailability;
use App\Models\DriverProfile;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use App\Realtime\StreamClient;
use App\Services\Drivers\DriverLocationStore;
use App\Services\Drivers\DriverService;
use App\Services\Drivers\StaleDriverSweeper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/**
 * P8-T2: drivers:mark-stale-offline (every minute): an online driver with no ping for 5 minutes goes offline, and stale
 * positions leave Redis.
 */
class StaleDriverSweeperTest extends DriverLocationTestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');
        $this->travelTo($this->now);
    }

    private function ago(int $seconds): int
    {
        return $this->now->subSeconds($seconds)->getTimestamp();
    }

    private function sweep(): array
    {
        return app(StaleDriverSweeper::class)->sweep();
    }

    /** @return list<array{from: ?string, to: string}> */
    private function availabilityEvents(): array
    {
        return array_map(fn ($e) => ['from' => $e['envelope']->payload['from_availability'], 'to' => $e['envelope']->payload['to_availability']], $this->entries(RealtimeEventType::DriverAvailabilityChanged));
    }

    public function test_an_online_driver_with_no_ping_for_over_five_minutes_goes_offline_and_leaves_the_map(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($user->id, $this->ago(361));

        $this->assertSame(['marked_offline' => 1, 'pruned' => 0], $this->sweep());

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([['from' => 'online', 'to' => 'offline']], $this->availabilityEvents());
        $this->assertSame(0, $this->positionCount());
    }

    public function test_the_limit_is_five_minutes_to_the_second(): void
    {
        [$exactly, $exactlyProfile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        [$justUnder, $justUnderProfile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($exactly->id, $this->ago(300));
        $this->seedPosition($justUnder->id, $this->ago(299));

        $this->sweep();

        $this->assertSame(DriverAvailability::Offline, $exactlyProfile->fresh()->availability, 'five minutes without a ping');
        $this->assertSame(DriverAvailability::Online, $justUnderProfile->fresh()->availability, 'one second short');
        $this->assertNotNull($this->position($justUnder->id));
    }

    public function test_a_driver_who_just_went_online_is_not_taken_offline_for_a_position_left_from_an_earlier_session(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subSeconds(60)]);
        $this->seedPosition($user->id, $this->ago(3600)); // an hour old, from before they went online a minute ago

        $this->assertSame(['marked_offline' => 0, 'pruned' => 0], $this->sweep());
        $this->assertSame(DriverAvailability::Online, $profile->fresh()->availability);

        // Still silent five minutes after going online: now they are quiet.
        $this->travelTo($this->now->addMinutes(5));
        $this->assertSame(['marked_offline' => 1, 'pruned' => 0], $this->sweep());
        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
    }

    public function test_the_limit_comes_from_configuration(): void
    {
        config(['taxikosmos.driver_location.stale_after_minutes' => 2]);
        [$user, $profile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($user->id, $this->ago(150));

        $this->sweep();

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
    }

    public function test_a_driver_on_a_trip_is_never_touched_however_old_the_ping(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::OnTrip, ['last_online_at' => $this->now->subHours(5)]);
        $this->seedPosition($user->id, $this->ago(3600));

        $this->assertSame(['marked_offline' => 0, 'pruned' => 0], $this->sweep());

        $this->assertSame(DriverAvailability::OnTrip, $profile->fresh()->availability);
        $this->assertNotNull($this->position($user->id));
        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_an_online_driver_who_never_pinged_goes_offline_only_after_the_limit_since_going_online(): void
    {
        [, $silent] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subMinutes(6)]);
        [, $new] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subMinutes(4)]);
        [$pinging, $pingingProfile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subMinutes(30)]);
        $this->seedPosition($pinging->id, $this->ago(10));

        $this->assertSame(['marked_offline' => 1, 'pruned' => 0], $this->sweep());

        $this->assertSame(DriverAvailability::Offline, $silent->fresh()->availability);
        $this->assertSame(DriverAvailability::Online, $new->fresh()->availability, 'went online four minutes ago, still has time to send a first ping');
        $this->assertSame(DriverAvailability::Online, $pingingProfile->fresh()->availability);
    }

    public function test_a_stale_position_of_an_offline_driver_or_of_a_driver_who_no_longer_exists_is_pruned(): void
    {
        [$offline] = $this->driver(DriverAvailability::Offline);
        [$freshOffline] = $this->driver(DriverAvailability::Offline);
        $this->seedPosition($offline->id, $this->ago(900));
        $this->seedPosition($freshOffline->id, $this->ago(30));
        $this->seedPosition(987654, $this->ago(900));

        $this->assertSame(['marked_offline' => 0, 'pruned' => 2], $this->sweep());

        $this->assertNull($this->position($offline->id));
        $this->assertNull($this->position(987654));
        $this->assertNotNull($this->position($freshOffline->id), 'a recent ping is left alone');
        $this->assertSame([], $this->availabilityEvents(), 'pruning a position is not a state change');
    }

    public function test_a_ping_that_arrives_after_the_stale_list_was_read_saves_the_driver(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($user->id, $this->ago(10)); // the driver pinged just now ...
        $staleList = new class(app(StreamClient::class), $user->id) extends DriverLocationStore
        {
            public function __construct(StreamClient $redis, private readonly int $id)
            {
                parent::__construct($redis);
            }

            public function pingedAtOrBefore(int $unixTime): array
            {
                return [$this->id]; // ... but the list, read a moment earlier, still names them
            }
        };

        $result = (new StaleDriverSweeper($staleList))->sweep();

        $this->assertSame(['marked_offline' => 0, 'pruned' => 0], $result);
        $this->assertSame(DriverAvailability::Online, $profile->fresh()->availability);
        $this->assertNotNull($this->position($user->id));
    }

    public function test_the_driver_row_is_locked_inside_a_transaction_before_the_sweep_marks_them_offline(): void
    {
        // The ping time is read again under this lock, so a ping that lands meanwhile saves the driver.
        [$user] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($user->id, $this->ago(600));

        $locks = $this->profileLocksDuring(fn () => $this->sweep());

        $this->assertNotEmpty($locks, 'marking a driver offline locks their profile row');
        foreach ($locks as $lock) {
            $this->assertGreaterThanOrEqual(1, $lock['depth']);
        }
    }

    public function test_a_second_run_changes_nothing_and_publishes_nothing_more(): void
    {
        [$user] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($user->id, $this->ago(600));
        $this->sweep();

        $this->assertSame(['marked_offline' => 0, 'pruned' => 0], $this->sweep());

        $this->assertCount(1, $this->availabilityEvents());
    }

    public function test_more_drivers_than_one_chunk_are_all_handled(): void
    {
        $profiles = DriverProfile::factory()->count(205)->approved()->online()->create(['last_online_at' => $this->now->subHour()]);
        foreach ($profiles as $profile) {
            $this->seedPosition($profile->user_id, $this->ago(700));
        }
        $neverPinged = DriverProfile::factory()->count(205)->approved()->online()->create(['last_online_at' => $this->now->subHour()]);

        $this->assertSame(['marked_offline' => 410, 'pruned' => 0], $this->sweep());

        $this->assertSame(0, DriverProfile::query()->where('availability', DriverAvailability::Online->value)->count());
        $this->assertSame(0, $this->positionCount());
        $this->assertSame(410, $this->streamLength(RealtimeEventType::DriverAvailabilityChanged));
        $this->assertCount(205, $neverPinged);
    }

    public function test_the_command_reports_what_it_did(): void
    {
        [$user] = $this->driver(DriverAvailability::Online, ['last_online_at' => $this->now->subHour()]);
        $this->seedPosition($user->id, $this->ago(600));

        $this->artisan('drivers:mark-stale-offline')->expectsOutput('Marked 1 driver(s) offline, pruned 0 stale position(s).')->assertExitCode(0);
    }

    public function test_it_is_scheduled_every_minute_without_overlapping_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'drivers:mark-stale-offline'));

        $this->assertNotNull($event, 'drivers:mark-stale-offline is not scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_suspending_a_driver_takes_the_position_off_the_map_at_once(): void
    {
        [$user, $profile] = $this->driver();
        $this->seedPosition($user->id, $this->ago(5));
        $admin = User::factory()->create(['is_admin' => true]); // the actor is only recorded; no role is needed here

        app(DriverService::class)->suspend($admin, $profile, 'Safety complaint');

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame(0, $this->positionCount());
    }
}
