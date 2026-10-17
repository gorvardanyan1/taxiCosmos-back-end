<?php

namespace Tests\Feature\Payments;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TripCancelledBy;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Exceptions\PaymentException;
use App\Exceptions\PaymentGatewayException;
use App\Models\DriverProfile;
use App\Models\Transaction;
use App\Models\Trip;
use App\Services\Payments\OutstandingBalance;
use App\Services\Payments\PaymentProcessor;
use App\Services\Payments\TripCardPayments;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

/**
 * P6-T2 · the card trip flow: hold on request (estimate + 20%), capture the final fare on completion (the part above the hold as
 * a separate charge), void on a free cancellation, renew holds about to lapse, and a failed final payment becomes an outstanding
 * balance that blocks the next card trip.
 */
class TripCardPaymentsTest extends CardPaymentsTestCase
{
    private TripCardPayments $trips;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trips = app(TripCardPayments::class);
    }

    public function test_a_requested_card_trip_holds_the_estimate_plus_the_buffer(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $trip = $this->cardTrip($rider, $card, 'requested', ['estimated_fare_amount' => 150000]);

        $hold = $this->trips->hold($trip);

        $this->assertSame(TransactionType::Authorization, $hold->type);
        $this->assertSame(TransactionStatus::Succeeded, $hold->status);
        $this->assertSame(180000, $hold->amount);
        $this->assertSame('AMD', $hold->currency);
        $this->assertSame($trip->id, $hold->trip_id);
        $this->assertSame("trip:{$trip->id}:hold", $hold->idempotency_key);
        $this->assertSame(TripPaymentStatus::Authorized, $trip->fresh()->payment_status);
    }

    public function test_the_buffer_is_configurable_and_rounds_up(): void
    {
        config(['payments.hold_buffer_percent' => 15]);
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested', ['estimated_fare_amount' => 1001]);

        // 1001 × 1.15 = 1151.15 → 1152: the hold never falls short by a rounding.
        $this->assertSame(1152, $this->trips->hold($trip)->amount);
    }

    public function test_holding_again_for_the_same_trip_reuses_the_hold(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');

        $first = $this->trips->hold($trip);
        $second = $this->trips->hold($trip);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->gateway()->moneyMovements());
    }

    public function test_a_declined_hold_refuses_the_trip_with_402(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider, 'declined'), 'requested');

        try {
            $this->trips->hold($trip);
            $this->fail('A declined hold was accepted.');
        } catch (PaymentException $e) {
            $this->assertSame('card_declined', $e->errorCode);
            $this->assertSame(402, $e->status);
        }

        $this->assertSame(TransactionStatus::Failed, Transaction::query()->sole()->status);
        $this->assertSame(TripPaymentStatus::Unpaid, $trip->fresh()->payment_status);
    }

    public function test_completion_captures_the_final_fare_from_the_hold(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');
        $hold = $this->trips->hold($trip);
        $trip = $this->complete($trip, 140000);

        $this->trips->complete($trip);

        $capture = Transaction::query()->where('type', 'capture')->sole();
        $this->assertSame(140000, $capture->amount);
        $this->assertSame($hold->id, $capture->parent_transaction_id);
        $this->assertSame(TransactionStatus::Succeeded, $capture->status);
        $this->assertSame(0, Transaction::query()->where('type', 'charge')->count());
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
        $this->assertSame(['authorize', 'capture'], array_column($this->gateway()->calls, 'op'));
    }

    public function test_a_fare_above_the_hold_captures_the_hold_and_charges_the_difference(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $trip = $this->cardTrip($rider, $card, 'requested');
        $this->trips->hold($trip); // 180 000
        $trip = $this->complete($trip, 200000);

        $this->trips->complete($trip);

        $capture = Transaction::query()->where('type', 'capture')->sole();
        $extra = Transaction::query()->where('type', 'charge')->sole();
        $this->assertSame(180000, $capture->amount);
        $this->assertSame(20000, $extra->amount);
        $this->assertSame(TransactionStatus::Succeeded, $extra->status);
        $this->assertSame($card->id, $extra->payment_method_id);
        $this->assertSame($trip->id, $extra->trip_id);
        $this->assertSame("trip:{$trip->id}:extra", $extra->idempotency_key);
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);

        // Running completion again (a retried job) moves no more money.
        $this->trips->complete($trip->fresh());
        $this->assertSame(3, $this->gateway()->moneyMovements());
        $this->assertSame(3, Transaction::query()->count());
    }

    public function test_a_retry_after_the_extra_charge_timed_out_finishes_with_one_charge(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');
        $this->trips->hold($trip);
        $trip = $this->complete($trip, 200000);
        $this->gateway()->calls = [];
        // The capture goes through; the gateway call after it (the extra charge) times out.
        $gateway = $this->gateway();
        $this->app->make(PaymentProcessor::class)->capture(app(TripCardPayments::class)->currentHold($trip), 180000, "trip:{$trip->id}:capture");
        $gateway->timeoutNext(applied: true);

        try {
            $this->trips->complete($trip);
            $this->fail('The timeout was swallowed.');
        } catch (PaymentGatewayException) {
        }

        $this->assertSame(TripPaymentStatus::Authorized, $trip->fresh()->payment_status);
        $this->trips->complete($trip->fresh());

        $this->assertSame(1, Transaction::query()->where('type', 'charge')->count());
        $this->assertSame(TransactionStatus::Succeeded, Transaction::query()->where('type', 'charge')->sole()->status);
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
        $this->assertSame(2, $gateway->moneyMovements()); // the capture and one extra charge
    }

    public function test_a_fare_exactly_equal_to_the_hold_is_captured_with_no_extra_charge(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested', ['estimated_fare_amount' => 150000]);
        $hold = $this->trips->hold($trip);
        $this->assertSame(180000, $hold->amount);
        $trip = $this->complete($trip, 180000);

        $this->trips->complete($trip);

        $capture = Transaction::query()->where('type', 'capture')->sole();
        $this->assertSame(180000, $capture->amount);
        $this->assertSame(TransactionStatus::Succeeded, $capture->status);
        $this->assertSame(0, Transaction::query()->where('type', 'charge')->count());
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
        $this->assertSame(['authorize', 'capture'], array_column($this->gateway()->calls, 'op'));
    }

    public function test_a_failed_capture_makes_the_trip_payment_failed_and_an_outstanding_balance(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider, 'capturefails'), 'requested');
        $this->trips->hold($trip);
        $trip = $this->complete($trip, 150000);

        $this->trips->complete($trip);

        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);
        $this->assertSame([['amount' => 150000, 'currency' => 'AMD']], app(OutstandingBalance::class)->totals($rider));
    }

    public function test_a_failed_extra_charge_leaves_only_the_difference_owing(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $trip = $this->cardTrip($rider, $card, 'requested');
        $this->trips->hold($trip);
        // The card starts declining after the hold.
        $card->forceFill(['gateway_payment_method_id' => 'fake_pm_declined_'.$card->id])->save();
        $trip = $this->complete($trip, 200000);

        $this->trips->complete($trip->fresh());

        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);
        $this->assertSame([['amount' => 20000, 'currency' => 'AMD']], app(OutstandingBalance::class)->totals($rider));
    }

    public function test_a_retried_completion_after_the_rider_paid_the_balance_keeps_the_trip_paid(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider, 'capturefails', ['is_default' => true]), 'requested');
        $this->trips->hold($trip);
        $trip = $this->complete($trip, 150000);
        $this->trips->complete($trip);
        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);
        app(OutstandingBalance::class)->pay($rider, $this->card($rider), 'retry-pay-0001');
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
        $rows = Transaction::query()->count();

        // A completion job that runs again after the rider paid: the failed capture is not retried and nothing is charged.
        $this->trips->complete($trip->fresh());

        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
        $this->assertSame($rows, Transaction::query()->count());
        $this->assertSame([], app(OutstandingBalance::class)->totals($rider));
    }

    public function test_an_outstanding_balance_refuses_the_next_card_trip_without_a_gateway_call(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $this->cardTrip($rider, $card, 'completed', ['payment_status' => TripPaymentStatus::PaymentFailed]);
        $next = $this->cardTrip($rider, $card, 'requested');

        try {
            $this->trips->hold($next);
            $this->fail('A card trip was held while money is owed.');
        } catch (PaymentException $e) {
            $this->assertSame('outstanding_balance', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_a_free_cancellation_voids_the_hold(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');
        $hold = $this->trips->hold($trip);
        $trip->fresh()->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider, 'Changed my mind');

        $this->trips->cancel($trip->fresh());

        $void = Transaction::query()->where('type', 'void')->sole();
        $this->assertSame($hold->id, $void->parent_transaction_id);
        $this->assertSame(TransactionStatus::Succeeded, $void->status);
        $this->assertSame(0, Transaction::query()->whereIn('type', ['capture', 'charge'])->count());
        $this->assertSame(TripPaymentStatus::Unpaid, $trip->fresh()->payment_status);
        $this->assertNull($this->trips->currentHold($trip));
    }

    public function test_a_cancellation_with_a_fee_captures_the_fee(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');
        $this->trips->hold($trip);
        $trip->fresh()->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider, 'Late', ['fare_amount' => 50000]);

        $this->trips->cancel($trip->fresh());

        $this->assertSame(50000, Transaction::query()->where('type', 'capture')->sole()->amount);
        $this->assertSame(0, Transaction::query()->where('type', 'void')->count());
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
    }

    public function test_cash_trips_make_no_gateway_call(): void
    {
        $rider = $this->rider();
        $trip = Trip::factory()->create(['rider_id' => $rider->id, 'payment_method' => TripPaymentMethod::Cash]);

        foreach ([fn () => $this->trips->hold($trip), fn () => $this->trips->complete($trip), fn () => $this->trips->cancel($trip)] as $step) {
            try {
                $step();
                $this->fail('A cash trip reached the card flow.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->gateway()->calls);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_completing_a_trip_that_is_not_completed_takes_nothing(): void
    {
        $rider = $this->rider();
        // Still in progress, but with a fare on it: only the status guard stops the capture.
        $trip = $this->cardTrip($rider, $this->card($rider), 'inProgress', ['fare_amount' => 150000, 'commission_amount' => 0, 'driver_earnings_amount' => 0]);
        $this->trips->hold($trip);
        $calls = count($this->gateway()->calls);

        try {
            $this->trips->complete($trip->fresh());
            $this->fail('A trip that is not completed was paid.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The trip must be completed.', $e->getMessage());
        }

        $this->assertSame($calls, count($this->gateway()->calls));
        $this->assertSame(0, Transaction::query()->whereIn('type', ['capture', 'charge'])->count());
        $this->assertSame(TripPaymentStatus::Authorized, $trip->fresh()->payment_status);
    }

    public function test_cancelling_a_trip_that_is_not_cancelled_takes_nothing(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'completed', ['fare_amount' => 150000, 'commission_amount' => 0, 'driver_earnings_amount' => 0]);
        $this->trips->hold($trip);

        try {
            $this->trips->cancel($trip->fresh());
            $this->fail('A trip that is not cancelled was settled as cancelled.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The trip must be cancelled.', $e->getMessage());
        }

        $this->assertSame(0, Transaction::query()->whereIn('type', ['capture', 'charge', 'void'])->count());
    }

    public function test_a_completed_cash_trip_is_never_paid_through_the_gateway(): void
    {
        $rider = $this->rider();
        $trip = Trip::factory()->completed()->create(['rider_id' => $rider->id, 'payment_method' => TripPaymentMethod::Cash]);

        try {
            $this->trips->complete($trip);
            $this->fail('A cash trip was paid through the gateway.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Only card trips are paid through the gateway.', $e->getMessage());
        }

        $this->assertSame([], $this->gateway()->calls);
        $this->assertSame(TripPaymentStatus::Unpaid, $trip->fresh()->payment_status);
    }

    public function test_a_card_trip_without_a_live_card_of_the_rider_is_refused(): void
    {
        $rider = $this->rider();
        $foreign = $this->card($this->rider('+37491210445'));
        $trip = $this->cardTrip($rider, $foreign, 'requested');

        try {
            $this->trips->hold($trip);
            $this->fail('Another rider\'s card was held.');
        } catch (PaymentException $e) {
            $this->assertSame('no_payment_method', $e->errorCode);
        }

        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_holds_about_to_lapse_on_a_running_trip_are_renewed_and_the_old_one_released(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $running = $this->cardTrip($rider, $card, 'requested');
        $old = $this->trips->hold($running);
        $finished = $this->cardTrip($rider, $card, 'requested');
        $finishedHold = $this->trips->hold($finished);
        $finished->fresh()->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider, 'x');

        // Stripe keeps a hold 168 h; renewal happens from 144 h (24 h margin).
        $this->travel(143)->hours();
        $this->assertSame(0, $this->trips->renewExpiringHolds());

        $this->travel(2)->hours();
        Artisan::call('payments:refresh-holds');
        $this->assertStringContainsString('1 hold(s) renewed.', Artisan::output());

        $new = $this->trips->currentHold($running);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($old->amount, $new->amount);
        $this->assertSame(TransactionStatus::Succeeded, Transaction::query()->where('type', 'void')->where('parent_transaction_id', $old->id)->sole()->status);
        // The finished trip's hold is left alone.
        $this->assertSame(0, Transaction::query()->where('parent_transaction_id', $finishedHold->id)->count());

        // Run again: the new hold is fresh, nothing more happens; the trip still captures from it after the old hold lapsed.
        $this->assertSame(0, $this->trips->renewExpiringHolds());
        $this->travel(30)->hours();
        $this->trips->complete($this->complete($running, 150000));
        $this->assertSame($new->id, Transaction::query()->where('type', 'capture')->sole()->parent_transaction_id);
        $this->assertSame(TripPaymentStatus::Paid, $running->fresh()->payment_status);
    }

    public function test_a_declined_renewal_keeps_the_old_hold_and_voids_nothing(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $trip = $this->cardTrip($rider, $card, 'requested');
        $old = $this->trips->hold($trip);
        // The card starts declining before the hold would lapse.
        $card->forceFill(['gateway_payment_method_id' => 'fake_pm_declined_'.$card->id])->save();
        $this->travel(145)->hours();

        $this->assertSame(0, $this->trips->renewExpiringHolds());

        $this->assertSame($old->id, $this->trips->currentHold($trip)->id);
        $this->assertSame(0, Transaction::query()->where('type', 'void')->count());
        $renewal = Transaction::query()->where('idempotency_key', "trip:{$trip->id}:hold:renew:{$old->id}")->sole();
        $this->assertSame(TransactionStatus::Failed, $renewal->status);
        $this->assertSame('card_declined', $renewal->failure_code);
        // The trip is untouched: still authorized on the old hold.
        $this->assertSame(TripPaymentStatus::Authorized, $trip->fresh()->payment_status);
    }

    public function test_a_renewal_that_times_out_keeps_the_old_hold_and_the_next_run_finishes_it_once(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');
        $old = $this->trips->hold($trip);
        $this->travel(145)->hours();
        $this->gateway()->timeoutNext(applied: false);

        $this->assertSame(0, $this->trips->renewExpiringHolds());

        $this->assertSame($old->id, $this->trips->currentHold($trip)->id);
        $this->assertSame(0, Transaction::query()->where('type', 'void')->count());

        // The next run retries the same renewal key: one new hold, the old one released.
        $this->assertSame(1, $this->trips->renewExpiringHolds());
        $new = $this->trips->currentHold($trip);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(2, Transaction::query()->where('type', 'authorization')->where('status', 'succeeded')->count());
        $this->assertSame(1, Transaction::query()->where('type', 'void')->where('parent_transaction_id', $old->id)->count());
    }

    public function test_a_hold_that_lapsed_without_renewal_fails_the_capture(): void
    {
        $rider = $this->rider();
        $trip = $this->cardTrip($rider, $this->card($rider), 'requested');
        $this->trips->hold($trip);
        $this->travel(169)->hours();

        $this->trips->complete($this->complete($trip, 150000));

        $this->assertSame('authorization_expired', Transaction::query()->where('type', 'capture')->sole()->failure_code);
        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);
    }

    public function test_the_renewal_job_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'payments:refresh-holds'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    /** Drive the trip to completed with this final fare (through the real state machine). */
    private function complete(Trip $trip, int $fare): Trip
    {
        $trip = $trip->fresh();
        $driver = DriverProfile::factory()->approved()->create();
        $trip->transitionTo(TripStatus::Matched, attributes: ['driver_id' => $driver->id]);
        $trip->transitionTo(TripStatus::Arrived);
        $trip->transitionTo(TripStatus::InProgress);
        $trip->transitionTo(TripStatus::Completed, attributes: ['fare_amount' => $fare, 'commission_amount' => intdiv($fare, 5), 'driver_earnings_amount' => $fare - intdiv($fare, 5)]);

        return $trip->fresh();
    }
}
