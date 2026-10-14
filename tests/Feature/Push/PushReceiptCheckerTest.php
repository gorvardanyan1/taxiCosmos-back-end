<?php

namespace Tests\Feature\Push;

use App\Data\PushResult;
use App\Enums\AppClient;
use App\Exceptions\PushDeliveryException;
use App\Models\Device;
use App\Models\PushTicket;
use App\Services\Push\PushReceiptChecker;
use Illuminate\Console\Scheduling\Schedule;

/**
 * P8-T4: "Invalid tokens reported by the push service are revoked automatically", through the receipts Expo publishes later.
 */
class PushReceiptCheckerTest extends PushTestCase
{
    private function ticket(Device $device, string $id, int $minutesAgo): PushTicket
    {
        return PushTicket::query()->create(['device_id' => $device->id, 'ticket_id' => $id, 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    private function device(string $label): Device
    {
        $this->register($this->signedIn($this->driverUser(), AppClient::Driver, "install-{$label}"), $this->token($label))->assertCreated();

        return Device::query()->where('push_token', $this->token($label))->sole();
    }

    public function test_a_device_the_receipt_says_is_not_registered_is_revoked_and_its_ticket_dropped(): void
    {
        $gone = $this->device('gone');
        $fine = $this->device('fine');
        $this->ticket($gone, 'tk-gone', 20);
        $this->ticket($fine, 'tk-fine', 20);
        $this->push->receiptResults = ['tk-gone' => PushResult::invalidToken(), 'tk-fine' => PushResult::accepted()];

        $this->artisan('push:check-receipts')->expectsOutput('Read 2 receipt(s), revoked 1 device(s), dropped 0 old ticket(s).')->assertExitCode(0);

        $this->assertNotNull($gone->fresh()->revoked_at);
        $this->assertNull($fine->fresh()->revoked_at);
        $this->assertSame(0, PushTicket::query()->count(), 'read tickets are removed');
    }

    public function test_a_ticket_without_a_receipt_yet_waits_for_the_next_run(): void
    {
        $device = $this->device('wait');
        $this->ticket($device, 'tk-not-ready', 20);
        $this->push->receiptResults = [];

        $this->artisan('push:check-receipts')->assertExitCode(0);

        $this->assertSame(1, PushTicket::query()->count());
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_only_tickets_older_than_the_delay_are_asked_about(): void
    {
        $device = $this->device('delay');
        $this->ticket($device, 'tk-old', 15);
        $this->ticket($device, 'tk-new', 14);
        $this->push->receiptResults = [];

        $this->artisan('push:check-receipts')->assertExitCode(0);

        $this->assertSame([['tk-old']], $this->push->receiptRequests);
    }

    public function test_tickets_older_than_the_retention_are_dropped_unread(): void
    {
        $device = $this->device('stale');
        $this->ticket($device, 'tk-ancient', 25 * 60);
        $this->ticket($device, 'tk-day', 23 * 60);
        $this->push->receiptResults = [];

        $this->artisan('push:check-receipts')->expectsOutput('Read 0 receipt(s), revoked 0 device(s), dropped 1 old ticket(s).')->assertExitCode(0);

        $this->assertSame(['tk-day'], PushTicket::query()->pluck('ticket_id')->all());
    }

    public function test_a_receipt_for_an_already_revoked_device_is_not_counted_twice(): void
    {
        $device = $this->device('twice');
        $device->forceFill(['revoked_at' => now()->subHour()])->save();
        $this->ticket($device, 'tk-1', 30);
        $this->push->receiptResults = ['tk-1' => PushResult::invalidToken()];

        $this->artisan('push:check-receipts')->expectsOutput('Read 1 receipt(s), revoked 0 device(s), dropped 0 old ticket(s).')->assertExitCode(0);
    }

    public function test_a_receipt_failure_leaves_the_tickets_for_the_next_run(): void
    {
        $device = $this->device('down');
        $this->ticket($device, 'tk-1', 30);
        $this->push->down = true;

        $this->expectException(PushDeliveryException::class);

        try {
            app(PushReceiptChecker::class)->check();
        } finally {
            $this->assertSame(1, PushTicket::query()->count());
        }
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'push:check-receipts'));

        $this->assertNotNull($event, 'push:check-receipts is not scheduled');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
