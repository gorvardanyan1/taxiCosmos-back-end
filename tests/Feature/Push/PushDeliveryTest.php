<?php

namespace Tests\Feature\Push;

use App\Enums\AppClient;
use App\Enums\PushType;
use App\Enums\UserStatus;
use App\Exceptions\PushDeliveryException;
use App\Jobs\SendPushNotification;
use App\Models\Device;
use App\Models\PushTicket;
use App\Models\User;
use App\Services\Push\PushDelivery;
use App\Services\Push\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P8-T4: who a notification goes to, what it says (in the user's language, with ids only), and what is done with the answer.
 */
class PushDeliveryTest extends PushTestCase
{
    private function notifier(): PushNotifier
    {
        return app(PushNotifier::class);
    }

    /** A person with both apps, each with a device. */
    private function bothApps(array $attributes = []): User
    {
        $user = User::factory()->rider()->driver()->withPhone('+37491607777')->create($attributes);
        $this->register($this->signedIn($user, AppClient::Rider, 'install-R'), $this->token('rider'))->assertCreated();
        $this->register($this->signedIn($user, AppClient::Driver, 'install-D'), $this->token('driver'))->assertCreated();

        return $user;
    }

    /**
     * @return array<string, array{PushType, list<string>}>
     */
    public static function audiences(): array
    {
        return [
            'trip offer: driver app' => [PushType::TripOffer, ['driver']],
            'driver matched: rider app' => [PushType::TripMatched, ['rider']],
            'driver arrived: rider app' => [PushType::TripArrived, ['rider']],
            'trip completed: rider app' => [PushType::TripCompleted, ['rider']],
            'no driver found: rider app' => [PushType::NoDriverFound, ['rider']],
            'document approved: driver app' => [PushType::DocumentApproved, ['driver']],
            'document rejected: driver app' => [PushType::DocumentRejected, ['driver']],
            'payout paid: driver app' => [PushType::PayoutPaid, ['driver']],
            'support reply: both apps' => [PushType::SupportReply, ['rider', 'driver']],
            'account suspended: both apps' => [PushType::AccountSuspended, ['rider', 'driver']],
        ];
    }

    /**
     * @param  list<string>  $apps
     */
    #[DataProvider('audiences')]
    public function test_each_notification_goes_to_the_apps_it_is_for(PushType $type, array $apps): void
    {
        $user = $this->bothApps();

        $this->notifier()->notify($user, $type, ['trip_id' => 5]);

        $expected = array_map(fn (string $app) => $this->token($app), $apps);
        $this->assertEqualsCanonicalizing($expected, $this->push->tokens());
    }

    public function test_a_trip_offer_is_urgent_in_every_way_and_everything_else_is_not(): void
    {
        config(['taxikosmos.push.offer_ttl_seconds' => 45]);
        $user = $this->bothApps();

        $this->notifier()->notify($user, PushType::TripOffer, ['offer_id' => 3, 'trip_id' => 9]);
        $this->notifier()->notify($user, PushType::DocumentApproved, ['document_id' => 4]);

        [$offer, $document] = $this->push->sent;
        $this->assertSame([true, 'trip_offers', true, 45], [$offer->highPriority, $offer->androidChannel, $offer->timeSensitive, $offer->ttlSeconds]);
        $this->assertSame([false, 'default', false, 86400], [$document->highPriority, $document->androidChannel, $document->timeSensitive, $document->ttlSeconds]);
    }

    public function test_every_push_carries_its_type_and_the_ids_it_was_given_and_nothing_else(): void
    {
        $user = $this->bothApps();

        $this->notifier()->notify($user, PushType::TripOffer, ['offer_id' => 3, 'trip_id' => 9]);

        $this->assertSame(['type' => 'trip_offer', 'offer_id' => 3, 'trip_id' => 9], $this->push->sent[0]->data);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badData(): array
    {
        return ['a list' => [[1, 2]], 'a float (money)' => [12.5], 'null' => [null], 'a bool' => [true], 'an object' => [new \stdClass]];
    }

    #[DataProvider('badData')]
    public function test_data_that_is_not_an_id_is_refused_before_anything_is_queued(mixed $value): void
    {
        $user = $this->bothApps();
        Queue::fake();

        try {
            $this->notifier()->notify($user, PushType::TripOffer, ['trip_id' => $value]);
            $this->fail('only ints and strings are ids');
        } catch (InvalidArgumentException) {
        }

        Queue::assertNothingPushed();
    }

    // ---- language ----

    public function test_every_text_is_translated_in_russian_and_armenian_and_differs_from_english(): void
    {
        foreach (PushType::cases() as $type) {
            foreach (['title', 'body'] as $part) {
                $english = $type->{$part}('en');
                $this->assertNotSame('', $english);
                foreach (['ru', 'hy'] as $locale) {
                    $this->assertNotSame($english, $type->{$part}($locale), "{$type->value} {$part} is not translated to {$locale}");
                }
            }
        }
    }

    public function test_the_texts_are_the_documented_ones_in_the_users_language(): void
    {
        $this->assertSame(['New trip request', 'A rider is waiting nearby. Tap to see the offer.'], [PushType::TripOffer->title('en'), PushType::TripOffer->body('en')]);
        $this->assertSame(['Новый заказ', 'Пассажир ждёт неподалёку. Нажмите, чтобы посмотреть предложение.'], [PushType::TripOffer->title('ru'), PushType::TripOffer->body('ru')]);
        $this->assertSame(['Ձեր վարորդը ժամանել է', 'Վարորդը սպասում է ձեզ նստեցման կետում։'], [PushType::TripArrived->title('hy'), PushType::TripArrived->body('hy')]);
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function locales(): array
    {
        return [
            'the users language wins over the phones' => ['ru', 'hy', 'ru'],
            'the phones language when the account has none' => [null, 'hy', 'hy'],
            'English when neither is known' => [null, null, 'en'],
            'an unsupported account language falls back to the phone' => ['fr', 'ru', 'ru'],
            'an unsupported phone language falls back to English' => [null, 'fr', 'en'],
        ];
    }

    #[DataProvider('locales')]
    public function test_the_text_is_in_the_users_language(?string $userLocale, ?string $deviceLocale, string $expected): void
    {
        $user = $this->driverUser(['locale' => $userLocale]);
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'), array_filter(['locale' => in_array($deviceLocale, ['en', 'ru', 'hy'], true) ? $deviceLocale : null]))->assertCreated();
        if ($deviceLocale === 'fr') {
            Device::query()->update(['locale' => 'fr']);
        }

        $this->notifier()->notify($user, PushType::TripOffer);

        $this->assertSame([PushType::TripOffer->title($expected), PushType::TripOffer->body($expected)], [$this->push->sent[0]->title, $this->push->sent[0]->body]);
    }

    public function test_the_language_is_the_one_the_user_has_when_the_push_is_sent_not_when_it_was_queued(): void
    {
        $user = $this->driverUser(['locale' => 'en']);
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'))->assertCreated();
        Queue::fake();
        $this->notifier()->notify($user, PushType::TripOffer);
        $user->forceFill(['locale' => 'ru'])->save();

        Queue::pushed(SendPushNotification::class)->sole()->handle(app(PushDelivery::class));

        $this->assertSame(PushType::TripOffer->title('ru'), $this->push->sent[0]->title);
    }

    // ---- who is eligible ----

    public function test_a_person_with_no_active_device_gets_nothing_queued(): void
    {
        $user = $this->driverUser();
        Queue::fake();

        $this->assertSame(0, $this->notifier()->notify($user, PushType::TripOffer));

        Queue::assertNothingPushed();
    }

    public function test_only_the_right_persons_active_devices_are_used(): void
    {
        $alice = $this->driverUser();
        $bob = $this->driverUser();
        $this->register($this->signedIn($alice, AppClient::Driver, 'a1'), $this->token('alice-1'))->assertCreated();
        $this->register($this->signedIn($alice, AppClient::Driver, 'a2'), $this->token('alice-2'))->assertCreated();
        $this->register($this->signedIn($bob, AppClient::Driver, 'b1'), $this->token('bob'))->assertCreated();
        Device::query()->where('push_token', $this->token('alice-2'))->update(['revoked_at' => now()]);

        $this->assertSame(1, $this->notifier()->notify($alice, PushType::TripOffer));

        $this->assertSame([$this->token('alice-1')], $this->push->tokens());
    }

    public function test_an_inactive_account_gets_nothing_except_the_suspension_notice(): void
    {
        $user = $this->driverUser();
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'))->assertCreated();
        $this->push->sent = [];
        $user->forceFill(['status' => UserStatus::Deactivated])->save();

        $this->assertSame(0, $this->notifier()->notify($user, PushType::TripOffer));
        $this->assertSame(0, $this->notifier()->notify($user, PushType::SupportReply));
        $this->assertSame(1, $this->notifier()->notify($user, PushType::AccountSuspended));
    }

    public function test_an_unknown_user_is_ignored(): void
    {
        $this->assertSame(0, $this->notifier()->notify(987654, PushType::TripOffer));
    }

    // ---- what the push service answers ----

    public function test_a_token_the_service_reports_invalid_is_revoked_and_the_other_devices_are_not(): void
    {
        $user = $this->driverUser();
        $this->register($this->signedIn($user, AppClient::Driver, 'p1'), $this->token('stale'))->assertCreated();
        $this->register($this->signedIn($user, AppClient::Driver, 'p2'), $this->token('fine'))->assertCreated();
        $this->push->invalidTokens = [$this->token('stale')];

        $this->notifier()->notify($user, PushType::TripOffer);

        $this->assertNotNull(Device::query()->where('push_token', $this->token('stale'))->sole()->revoked_at);
        $this->assertNull(Device::query()->where('push_token', $this->token('fine'))->sole()->revoked_at);
        $this->push->sent = [];
        $this->notifier()->notify($user, PushType::TripOffer);
        $this->assertSame([$this->token('fine')], $this->push->tokens(), 'the revoked token is not used again');
    }

    public function test_the_ticket_of_an_accepted_push_is_kept_for_its_receipt(): void
    {
        $user = $this->driverUser();
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'))->assertCreated();

        $this->notifier()->notify($user, PushType::TripOffer);

        $ticket = PushTicket::query()->sole();
        $this->assertSame(['ticket-1', Device::query()->sole()->id], [$ticket->ticket_id, $ticket->device_id]);
    }

    public function test_a_push_the_service_refuses_for_another_reason_does_not_revoke_the_device_and_is_logged_without_the_token(): void
    {
        $user = $this->driverUser();
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'))->assertCreated();
        $this->push->refusedTokens = [$this->token('one')];
        Log::spy();

        $this->notifier()->notify($user, PushType::TripOffer);

        $this->assertNull(Device::query()->sole()->revoked_at);
        $this->assertSame(0, PushTicket::query()->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['error'] === 'MessageTooBig' && ! str_contains(json_encode($context), 'ExponentPushToken'));
    }

    public function test_the_push_service_being_down_fails_the_job_so_it_retries_and_changes_nothing(): void
    {
        $user = $this->driverUser();
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'))->assertCreated();
        $this->push->down = true;

        $this->expectException(PushDeliveryException::class);

        try {
            $this->notifier()->notify($user, PushType::TripOffer);
        } finally {
            $this->assertNull(Device::query()->sole()->revoked_at);
            $this->assertSame(0, PushTicket::query()->count());
        }
    }

    public function test_the_job_retries_with_a_growing_delay(): void
    {
        $job = new SendPushNotification(1, PushType::TripOffer, [1], []);

        $this->assertSame(4, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff());
        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function test_the_push_is_queued_not_sent_inside_the_request(): void
    {
        $user = $this->driverUser();
        $this->register($this->signedIn($user, AppClient::Driver), $this->token('one'))->assertCreated();
        Queue::fake();

        $this->notifier()->notify($user, PushType::TripOffer);

        Queue::assertPushed(SendPushNotification::class, 1);
        $this->assertSame([], $this->push->sent, 'nothing is sent until the queue worker runs the job');
    }
}
