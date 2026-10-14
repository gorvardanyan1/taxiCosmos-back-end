<?php

namespace Tests\Feature\Support;

use App\Enums\AdminRole;
use App\Enums\AppClient;
use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use App\Models\DriverProfile;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\SafetyAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Account\AccountTestCase;

/**
 * P7-T5 · the rider and driver apps' support tickets: POST/GET /api/v1/support-tickets and POST .../{id}/messages.
 * Real PostgreSQL, real sessions and tokens; only the mail is faked.
 */
class SupportTicketMobileApiTest extends AccountTestCase
{
    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->rider = $this->rider();
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return array_merge(['category' => 'fare', 'subject' => 'Charged twice', 'message' => 'My card was charged two times.'], $over);
    }

    private function admin(AdminRole $role, array $attributes = []): User
    {
        return User::factory()->admin($role)->create($attributes);
    }

    private function open(User $user, array $over = [], AppClient $app = AppClient::Rider, array $headers = [])
    {
        return $this->postJson('/api/v1/support-tickets', $this->payload($over), $this->as($user, $app) + $headers);
    }

    private function ticketFor(User $user, array $state = [], AppClient $app = AppClient::Rider): SupportTicket
    {
        $ticket = SupportTicket::factory()->create(array_merge(['requester_id' => $user->id, 'requester_type' => $app], $state));
        SupportTicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $user->id, 'body' => 'First message']);

        return $ticket;
    }

    public function test_a_rider_opens_a_ticket_and_it_is_stored_with_its_first_message(): void
    {
        $response = $this->open($this->rider)->assertCreated();

        $ticket = SupportTicket::query()->sole();
        $this->assertSame($ticket->id, $response->json('data.id'));
        $this->assertSame(sprintf('TKT-%05d', $ticket->id), $ticket->code);
        $this->assertSame([$this->rider->id, AppClient::Rider, SupportCategory::Fare, SupportPriority::Normal, SupportStatus::Open, 'Charged twice', null],
            [$ticket->requester_id, $ticket->requester_type, $ticket->category, $ticket->priority, $ticket->status, $ticket->subject, $ticket->assigned_to]);
        $message = SupportTicketMessage::query()->sole();
        $this->assertSame([$ticket->id, $this->rider->id, 'My card was charged two times.', false], [$message->ticket_id, $message->author_id, $message->body, $message->is_internal_note]);
        $this->assertEqualsCanonicalizing(['id', 'code', 'subject', 'category', 'priority', 'status', 'trip_id', 'resolution', 'resolved_at', 'last_message_at', 'created_at'], array_keys($response->json('data')));
        $this->assertSame(['open', 'fare', 'normal', null], [$response->json('data.status'), $response->json('data.category'), $response->json('data.priority'), $response->json('data.resolution')]);
    }

    public function test_the_app_comes_from_the_token_not_from_the_body(): void
    {
        $driver = User::factory()->driver()->withPhone('+37491210444')->create();

        $this->open($driver, ['requester_type' => 'rider', 'requester_id' => $this->rider->id], AppClient::Driver)->assertCreated();

        $ticket = SupportTicket::query()->sole();
        $this->assertSame([$driver->id, AppClient::Driver], [$ticket->requester_id, $ticket->requester_type]);
    }

    public function test_a_ticket_can_point_at_the_riders_own_trip_and_a_drivers_own_trip(): void
    {
        $trip = Trip::factory()->matched()->create(['rider_id' => $this->rider->id]);
        // A driver signs in with a phone: without one the session can only add it.
        $driverUser = $trip->driver->user;
        $driverUser->forceFill(['phone' => '+37491210450'])->save();

        $this->open($this->rider, ['trip_id' => $trip->id])->assertCreated()->assertJsonPath('data.trip_id', $trip->id);
        $this->open($driverUser, ['trip_id' => $trip->id, 'category' => 'rider_behavior'], AppClient::Driver)->assertCreated()->assertJsonPath('data.trip_id', $trip->id);

        $this->assertSame(2, SupportTicket::query()->where('trip_id', $trip->id)->count());
    }

    public function test_someone_elses_trip_or_the_wrong_apps_trip_is_refused_without_saying_which(): void
    {
        $others = Trip::factory()->matched()->create();
        $mine = Trip::factory()->matched()->create(['rider_id' => $this->rider->id]);

        $this->open($this->rider, ['trip_id' => $others->id])->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);
        $this->open($this->rider, ['trip_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);
        // A rider's own trip, but from the driver app: they did not drive it.
        $this->open($this->rider, ['trip_id' => $mine->id], AppClient::Driver)->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_a_safety_ticket_is_urgent_and_emails_only_active_admins_who_handle_support_after_it_is_saved(): void
    {
        Notification::fake();
        $support = $this->admin(AdminRole::Support);
        $admin = $this->admin(AdminRole::Admin);
        $super = $this->admin(AdminRole::SuperAdmin);
        $finance = $this->admin(AdminRole::Finance);
        $dispatcher = $this->admin(AdminRole::Dispatcher);
        $suspended = $this->admin(AdminRole::Support);
        $suspended->forceFill(['status' => 'suspended'])->save();

        $response = $this->open($this->rider, ['category' => 'safety', 'subject' => 'Driver was speeding'])->assertCreated();

        $this->assertSame('urgent', $response->json('data.priority'));
        $this->assertSame(SupportPriority::Urgent, SupportTicket::query()->sole()->priority);
        Notification::assertSentTo([$support, $admin, $super], SafetyAlert::class, fn (SafetyAlert $alert, array $channels) => $channels === ['mail'] && $alert->ticket->is(SupportTicket::query()->sole()));
        Notification::assertNotSentTo([$finance, $dispatcher, $suspended, $this->rider], SafetyAlert::class);
        Notification::assertCount(3);
    }

    public function test_the_alert_mail_names_the_ticket_and_links_to_it_without_the_message(): void
    {
        $trip = Trip::factory()->matched()->create(['rider_id' => $this->rider->id]);
        $this->open($this->rider, ['category' => 'safety', 'trip_id' => $trip->id, 'message' => 'SECRET DETAILS OF THE INCIDENT'])->assertCreated();
        $ticket = SupportTicket::query()->sole();

        $mail = (new SafetyAlert($ticket->load('trip')))->toMail($this->admin(AdminRole::Support));

        $this->assertSame("Safety ticket {$ticket->code} needs attention", $mail->subject);
        $rendered = json_encode([$mail->introLines, $mail->actionUrl, $mail->actionText], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString($ticket->code, $rendered);
        $this->assertStringContainsString($trip->public_code, $rendered);
        $this->assertStringContainsString('/admin/support-tickets/'.$ticket->id, $rendered);
        $this->assertStringNotContainsString('SECRET DETAILS', $rendered);
    }

    public function test_other_categories_send_no_alert_and_start_normal(): void
    {
        Notification::fake();
        $this->admin(AdminRole::Support);

        foreach (SupportCategory::cases() as $category) {
            if ($category !== SupportCategory::Safety) {
                $this->open($this->rider, ['category' => $category->value])->assertCreated()->assertJsonPath('data.priority', 'normal');
            }
        }

        Notification::assertNothingSent();
    }

    public function test_an_invalid_request_is_refused_with_field_errors_and_saves_nothing(): void
    {
        $cases = [
            'no body' => [[], ['category', 'subject', 'message']],
            'bad category' => [['category' => 'refund_me'], ['category']],
            'short subject' => [['subject' => 'ab'], ['subject']],
            'long subject' => [['subject' => str_repeat('a', 161)], ['subject']],
            'empty message' => [['message' => ''], ['message']],
            'long message' => [['message' => str_repeat('a', 5001)], ['message']],
            'trip not a number' => [['trip_id' => 'abc'], ['trip_id']],
            'array subject' => [['subject' => ['x']], ['subject']],
        ];

        foreach ($cases as $name => [$over, $fields]) {
            $body = $name === 'no body' ? [] : $this->payload($over);
            $this->postJson('/api/v1/support-tickets', $body, $this->as($this->rider))->assertUnprocessable()->assertJsonValidationErrors($fields);
        }
        $this->open($this->rider, [], AppClient::Rider, ['Idempotency-Key' => 'short'])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(0, SupportTicketMessage::query()->count());
    }

    public function test_the_boundary_lengths_are_accepted(): void
    {
        $this->open($this->rider, ['subject' => 'abc', 'message' => 'x'])->assertCreated();
        $this->open($this->rider, ['subject' => str_repeat('a', 160), 'message' => str_repeat('b', 5000)])->assertCreated();
    }

    public function test_a_guest_a_pending_phone_session_and_an_admin_cannot_use_it(): void
    {
        $this->postJson('/api/v1/support-tickets', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/support-tickets')->assertUnauthorized();
        $this->getJson('/api/v1/support-tickets/1')->assertUnauthorized();
        $this->postJson('/api/v1/support-tickets/1/messages', ['body' => 'hi'])->assertUnauthorized();

        $noPhone = User::factory()->rider()->create();
        $this->postJson('/api/v1/support-tickets', $this->payload(), $this->as($noPhone))->assertForbidden();
        $this->postJson('/api/v1/support-tickets', $this->payload(), $this->bearer($this->admin(AdminRole::Admin)->createToken('x', ['rider'])->plainTextToken))->assertForbidden()->assertJsonPath('code', 'admin_account');
        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_a_suspended_account_can_still_ask_support_about_it(): void
    {
        $user = User::factory()->rider()->withPhone('+37491210445')->create();
        $headers = $this->as($user);
        User::query()->whereKey($user->id)->update(['status' => 'suspended']);

        $this->postJson('/api/v1/support-tickets', $this->payload(['subject' => 'Why am I suspended']), $headers)->assertCreated();
        $this->getJson('/api/v1/support-tickets', $headers)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_same_idempotency_key_never_opens_a_second_ticket(): void
    {
        $key = ['Idempotency-Key' => 'retry-key-0001'];

        $first = $this->open($this->rider, [], AppClient::Rider, $key)->assertCreated();
        $again = $this->open($this->rider, [], AppClient::Rider, $key)->assertOk();

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame(1, SupportTicketMessage::query()->count());
    }

    public function test_the_same_key_for_a_different_request_is_a_conflict_and_another_person_has_their_own_keys(): void
    {
        $key = ['Idempotency-Key' => 'retry-key-0002'];
        $this->open($this->rider, [], AppClient::Rider, $key)->assertCreated();

        $this->open($this->rider, ['subject' => 'Something else'], AppClient::Rider, $key)->assertStatus(409);
        $this->open($this->rider, ['category' => 'lost_item'], AppClient::Rider, $key)->assertStatus(409);
        $other = User::factory()->rider()->withPhone('+37491210446')->create();
        $this->open($other, [], AppClient::Rider, $key)->assertCreated();

        $this->assertSame(2, SupportTicket::query()->count());
    }

    public function test_the_list_is_only_my_tickets_for_this_app_newest_activity_first(): void
    {
        $older = $this->ticketFor($this->rider, ['subject' => 'Older', 'last_message_at' => now()->subDay()]);
        $newer = $this->ticketFor($this->rider, ['subject' => 'Newer', 'last_message_at' => now()]);
        $this->ticketFor(User::factory()->rider()->create(), ['subject' => 'Not mine']);
        $this->ticketFor($this->rider, ['subject' => 'As a driver', 'requester_type' => AppClient::Driver]);

        $response = $this->getJson('/api/v1/support-tickets', $this->as($this->rider))->assertOk();

        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2], $response->json('meta'));
        $this->assertArrayNotHasKey('messages', $response->json('data.0'), 'the list carries no conversation');
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 21) as $i) {
            $this->ticketFor($this->rider, ['subject' => "Ticket {$i}", 'last_message_at' => now()->subMinutes(30 - $i)]);
        }

        $first = $this->getJson('/api/v1/support-tickets', $this->as($this->rider))->assertOk();
        $second = $this->getJson('/api/v1/support-tickets?page=2', $this->as($this->rider))->assertOk();

        $this->assertCount(20, $first->json('data'));
        $this->assertCount(1, $second->json('data'));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 20, 'total' => 21], $second->json('meta'));
    }

    public function test_the_detail_shows_the_conversation_without_internal_notes_or_staff_names(): void
    {
        $ticket = $this->ticketFor($this->rider);
        $staff = $this->admin(AdminRole::Support, ['name' => 'Sam Staffer']);
        SupportTicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $staff->id, 'body' => 'We are looking into it.']);
        SupportTicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $staff->id, 'body' => 'INTERNAL: driver has history', 'is_internal_note' => true]);

        $response = $this->getJson("/api/v1/support-tickets/{$ticket->id}", $this->as($this->rider))->assertOk();

        $messages = $response->json('data.messages');
        $this->assertSame([['you', 'First message'], ['support', 'We are looking into it.']], array_map(fn ($m) => [$m['from'], $m['body']], $messages));
        $this->assertEqualsCanonicalizing(['id', 'from', 'body', 'created_at'], array_keys($messages[0]));
        $raw = $response->getContent();
        $this->assertStringNotContainsString('INTERNAL', $raw);
        $this->assertStringNotContainsString('Sam Staffer', $raw);
        $this->assertStringNotContainsString('assigned', $raw);
    }

    public function test_the_resolution_is_visible_to_the_requester(): void
    {
        $ticket = $this->ticketFor($this->rider, ['status' => SupportStatus::Resolved, 'resolved_at' => now(), 'resolution' => 'Refunded the extra charge.']);

        $this->getJson("/api/v1/support-tickets/{$ticket->id}", $this->as($this->rider))->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution', 'Refunded the extra charge.');
    }

    public function test_another_persons_ticket_and_the_other_apps_ticket_are_not_found(): void
    {
        $theirs = $this->ticketFor(User::factory()->rider()->create());
        $mineAsDriver = $this->ticketFor($this->rider, [], AppClient::Driver);
        $headers = $this->as($this->rider);

        $this->getJson("/api/v1/support-tickets/{$theirs->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/support-tickets/{$theirs->id}/messages", ['body' => 'hi'], $headers)->assertNotFound();
        $this->getJson("/api/v1/support-tickets/{$mineAsDriver->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/support-tickets/{$mineAsDriver->id}/messages", ['body' => 'hi'], $headers)->assertNotFound();
        $this->getJson('/api/v1/support-tickets/999999', $headers)->assertNotFound();
        $this->getJson('/api/v1/support-tickets/abc', $headers)->assertNotFound();

        $this->assertSame(0, SupportTicketMessage::query()->where('body', 'hi')->count());
    }

    public function test_a_message_is_added_to_my_ticket(): void
    {
        $ticket = $this->ticketFor($this->rider);

        $response = $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => 'More details.'], $this->as($this->rider))->assertCreated();

        $this->assertSame(['you', 'More details.', 'open'], [$response->json('data.from'), $response->json('data.body'), $response->json('data.ticket_status')]);
        $message = SupportTicketMessage::query()->where('body', 'More details.')->sole();
        $this->assertSame([$ticket->id, $this->rider->id, false], [$message->ticket_id, $message->author_id, $message->is_internal_note]);
        $this->assertNotNull($ticket->fresh()->last_message_at);
    }

    public function test_answering_a_staff_question_moves_the_ticket_back_to_in_progress(): void
    {
        $ticket = $this->ticketFor($this->rider, ['status' => SupportStatus::WaitingOnCustomer]);

        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Here it is.'], $this->as($this->rider))->assertCreated()->assertJsonPath('data.ticket_status', 'in_progress');

        $this->assertSame(SupportStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_writing_on_a_resolved_ticket_reopens_it_and_a_closed_one_takes_nothing(): void
    {
        $resolved = $this->ticketFor($this->rider, ['status' => SupportStatus::Resolved, 'resolved_at' => now(), 'resolution' => 'Done.']);
        $closed = $this->ticketFor($this->rider, ['status' => SupportStatus::Closed, 'resolved_at' => now(), 'resolution' => 'Done.']);
        $headers = $this->as($this->rider);

        $this->postJson("/api/v1/support-tickets/{$resolved->id}/messages", ['body' => 'Still broken.'], $headers)->assertCreated()->assertJsonPath('data.ticket_status', 'open');
        $this->postJson("/api/v1/support-tickets/{$closed->id}/messages", ['body' => 'Too late.'], $headers)->assertStatus(409);

        $fresh = $resolved->fresh();
        $this->assertSame([SupportStatus::Open, null, null], [$fresh->status, $fresh->resolved_at, $fresh->resolution]);
        $this->assertSame(0, SupportTicketMessage::query()->where('body', 'Too late.')->count());
        $this->assertSame(SupportStatus::Closed, $closed->fresh()->status);
    }

    public function test_a_retried_message_is_stored_once(): void
    {
        $ticket = $this->ticketFor($this->rider);
        $headers = $this->as($this->rider) + ['Idempotency-Key' => 'message-key-01'];

        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Once.'], $headers)->assertCreated();
        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Once.'], $headers)->assertOk();
        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Different.'], $headers)->assertStatus(409);

        $this->assertSame(1, SupportTicketMessage::query()->where('body', 'Once.')->count());
        $this->assertSame(0, SupportTicketMessage::query()->where('body', 'Different.')->count());
    }

    public function test_a_message_is_validated(): void
    {
        $ticket = $this->ticketFor($this->rider);
        $headers = $this->as($this->rider);

        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", [], $headers)->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => ''], $headers)->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->postJson("/api/v1/support-tickets/{$ticket->id}/messages", ['body' => str_repeat('a', 5001)], $headers)->assertUnprocessable()->assertJsonValidationErrors(['body']);

        $this->assertSame(1, SupportTicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_the_endpoints_are_rate_limited_per_person_with_retry_after(): void
    {
        config(['security.rate_limits.support_tickets' => 3]);
        $this->travelTo(now()->startOfMinute());
        $this->withMiddleware(ThrottleRequests::class);
        $headers = $this->as($this->rider);

        foreach (range(1, 3) as $_) {
            $this->getJson('/api/v1/support-tickets', $headers)->assertOk();
        }

        $this->getJson('/api/v1/support-tickets', $headers)->assertTooManyRequests()->assertHeader('Retry-After');
        // Another person has their own budget.
        $this->getJson('/api/v1/support-tickets', $this->as(User::factory()->rider()->withPhone('+37491210447')->create()))->assertOk();
    }

    public function test_a_driver_sees_driver_tickets_with_their_trip_requester_type(): void
    {
        $profile = DriverProfile::factory()->approved()->create();
        $driver = $profile->user;
        $driver->forceFill(['phone' => '+37491210451'])->save();
        $this->open($driver, ['category' => 'app_issue', 'subject' => 'App crashes'], AppClient::Driver)->assertCreated();

        $this->getJson('/api/v1/support-tickets', $this->as($driver, AppClient::Driver))->assertOk()->assertJsonPath('data.0.subject', 'App crashes');
        $this->getJson('/api/v1/support-tickets', $this->as($driver, AppClient::Rider))->assertOk()->assertJsonCount(0, 'data');
    }
}
