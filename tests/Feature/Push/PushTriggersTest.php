<?php

namespace Tests\Feature\Push;

use App\Enums\AppClient;
use App\Enums\DriverDocumentType;
use App\Enums\TripStatus;
use App\Enums\UserStatus;
use App\Events\Realtime\SupportTicketUpdated;
use App\Events\Realtime\TripOffer;
use App\Events\Realtime\TripStatusChanged;
use App\Events\Realtime\UserSuspended;
use App\Exceptions\DocumentReviewException;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\DriverDocuments\DriverDocumentReviewer;
use App\Services\Drivers\DriverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P8-T4: the events of the system become pushes: trip offer, driver matched / arrived / trip completed / no driver found,
 * document approved or rejected, support reply, account suspended. (Payout paid arrives with the payout task, which calls
 * PushNotifier with PushType::PayoutPaid.)
 */
class PushTriggersTest extends PushTestCase
{
    private User $driver;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->driverUser(['locale' => 'en']);
        $this->rider = $this->riderUser(['locale' => 'en']);
        $this->register($this->signedIn($this->driver, AppClient::Driver, 'driver-phone'), $this->token('driver'))->assertCreated();
        $this->register($this->signedIn($this->rider, AppClient::Rider, 'rider-phone'), $this->token('rider'))->assertCreated();
    }

    /** @return list<array{token: string, title: string, data: array<string, mixed>}> */
    private function pushes(): array
    {
        return array_map(fn ($m) => ['token' => $m->token, 'title' => $m->title, 'data' => $m->data], $this->push->sent);
    }

    // ---- trips ----

    public function test_a_trip_offer_reaches_the_driver_with_the_offer_and_trip_ids(): void
    {
        TripOffer::dispatch(11, 42, $this->driver->id, now()->addSeconds(15), 'A', 'B', 2500, 'AMD', 'economy');

        $this->assertSame([['token' => $this->token('driver'), 'title' => 'New trip request', 'data' => ['type' => 'trip_offer', 'offer_id' => 11, 'trip_id' => 42]]], $this->pushes());
        $this->assertTrue($this->push->sent[0]->highPriority);
        $this->assertSame('trip_offers', $this->push->sent[0]->androidChannel);
    }

    public function test_the_text_of_a_push_never_carries_the_addresses_or_the_fare_of_the_trip(): void
    {
        TripOffer::dispatch(11, 42, $this->driver->id, now()->addSeconds(15), 'Abovyan St 1', 'Airport Terminal 2', 2500, 'AMD', 'economy');

        $message = $this->push->sent[0];
        $this->assertStringNotContainsString('Abovyan', $message->body.$message->title.json_encode($message->data));
        $this->assertStringNotContainsString('2500', $message->body.json_encode($message->data));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function tripStatuses(): array
    {
        return [
            'matched' => ['matched', 'Driver found'],
            'arrived' => ['arrived', 'Your driver has arrived'],
            'completed' => ['completed', 'Trip completed'],
            'no driver found' => ['no_driver_found', 'No driver found'],
            'requested' => ['requested', null],
            'in progress' => ['in_progress', null],
            'cancelled' => ['cancelled', null],
        ];
    }

    #[DataProvider('tripStatuses')]
    public function test_the_trip_statuses_that_have_a_push_tell_the_rider_and_the_others_do_not(string $status, ?string $title): void
    {
        TripStatusChanged::dispatch(42, 'TK-42', TripStatus::Requested->value, $status, $this->rider->id, $this->driver->id);

        $expected = $title === null ? [] : [['token' => $this->token('rider'), 'title' => $title, 'data' => ['type' => match ($status) {
            'matched' => 'trip_matched', 'arrived' => 'trip_arrived', 'completed' => 'trip_completed', default => 'no_driver_found',
        }, 'trip_id' => 42]]];
        $this->assertSame($expected, $this->pushes());
    }

    // ---- suspension ----

    public function test_suspending_an_account_announces_it_on_every_app_of_that_person(): void
    {
        UserSuspended::dispatch($this->driver->id, UserStatus::Suspended->value);

        $this->assertSame([['token' => $this->token('driver'), 'title' => 'Account suspended', 'data' => ['type' => 'account_suspended']]], $this->pushes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function quietEndings(): array
    {
        return ['deactivated' => ['deactivated'], 'pending deletion' => ['pending_deletion'], 'active again' => ['active']];
    }

    #[DataProvider('quietEndings')]
    public function test_other_status_changes_are_not_announced(string $status): void
    {
        UserSuspended::dispatch($this->driver->id, $status);

        $this->assertSame([], $this->pushes());
    }

    // ---- support ----

    public function test_a_support_reply_reaches_the_requester_but_a_status_or_assignment_change_does_not(): void
    {
        SupportTicketUpdated::dispatch(5, 'TKT-5', 'open', $this->rider->id, 'status');
        SupportTicketUpdated::dispatch(5, 'TKT-5', 'open', $this->rider->id, 'assignment');
        $this->assertSame([], $this->pushes());

        SupportTicketUpdated::dispatch(5, 'TKT-5', 'open', $this->rider->id, 'reply');

        $this->assertSame([['token' => $this->token('rider'), 'title' => 'New reply from support', 'data' => ['type' => 'support_reply', 'ticket_id' => 5]]], $this->pushes());
    }

    // ---- documents (the real reviewer) ----

    private function pendingDocument(): DriverDocument
    {
        $profile = DriverProfile::factory()->create(['user_id' => $this->driver->id]);

        return DriverDocument::factory()->ofType(DriverDocumentType::License)->expiring('2099-01-01')->create(['driver_id' => $profile->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_approving_a_document_tells_the_driver(): void
    {
        $document = $this->pendingDocument();

        app(DriverDocumentReviewer::class)->approve($document, $this->admin());

        $this->assertSame([['token' => $this->token('driver'), 'title' => 'Document approved', 'data' => ['type' => 'document_approved', 'document_id' => $document->id]]], $this->pushes());
    }

    public function test_rejecting_a_document_tells_the_driver(): void
    {
        $document = $this->pendingDocument();

        app(DriverDocumentReviewer::class)->reject($document, $this->admin(), 'The photo is blurry');

        $this->assertSame([['token' => $this->token('driver'), 'title' => 'Document rejected', 'data' => ['type' => 'document_rejected', 'document_id' => $document->id]]], $this->pushes());
        $this->assertStringNotContainsString('blurry', $this->push->sent[0]->body, 'the reason is read in the app, not in the push');
    }

    public function test_a_review_that_is_refused_or_rolled_back_sends_no_push(): void
    {
        $document = $this->pendingDocument();
        $reviewer = app(DriverDocumentReviewer::class);

        DB::beginTransaction();
        $reviewer->approve($document, $this->admin());
        DB::rollBack();

        $this->assertSame([], $this->pushes(), 'a rolled back decision is never announced');
        $this->assertSame('pending', $document->fresh()->status->value);

        $reviewer->approve($document->fresh(), $this->admin());
        $this->push->sent = [];
        try {
            $reviewer->approve($document->fresh(), $this->admin());
        } catch (DocumentReviewException) {
        }
        $this->assertSame([], $this->pushes(), 'a second approval is refused and announces nothing');
    }

    public function test_a_real_admin_suspension_pushes_the_notice_and_ends_the_sessions(): void
    {
        $profile = DriverProfile::factory()->create(['user_id' => $this->driver->id]);
        $this->push->sent = [];

        app(DriverService::class)->suspend($this->admin(), $profile, 'Safety complaint');

        $this->assertSame(['account_suspended'], array_map(fn ($p) => $p['data']['type'], $this->pushes()));
        $this->assertSame(0, $this->driver->tokens()->count());
    }

    // ---- resilience ----

    public function test_a_push_that_cannot_be_queued_is_logged_and_does_not_break_the_change_that_caused_it(): void
    {
        config(['queue.default' => 'missing-connection']);
        Log::spy();

        UserSuspended::dispatch($this->driver->id, UserStatus::Suspended->value); // must not throw

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => ($context['user_id'] ?? null) === $this->driver->id && ($context['type'] ?? null) === 'account_suspended' && ! array_key_exists('data', $context))->once();
    }
}
