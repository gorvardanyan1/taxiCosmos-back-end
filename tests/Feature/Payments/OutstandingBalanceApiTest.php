<?php

namespace Tests\Feature\Payments;

use App\Enums\TransactionStatus;
use App\Enums\TripPaymentStatus;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use App\Services\Payments\OutstandingBalance;
use App\Services\Payments\PaymentProcessor;

/**
 * P6-T2 · GET /api/v1/account/outstanding and POST /api/v1/account/outstanding/pay: what failed card payments left owing, paid once
 * per idempotency key; the /me rider block shows it so the app asks for a card update.
 */
class OutstandingBalanceApiTest extends CardPaymentsTestCase
{
    private const KEY = 'pay-outstanding-0001';

    /** A completed card trip whose final payment failed, with $collected already taken. */
    private function failedTrip(User $rider, int $fare = 150000, int $collected = 0): Trip
    {
        $card = $this->card($rider);
        $trip = $this->cardTrip($rider, $card, 'completed', ['fare_amount' => $fare, 'commission_amount' => 0, 'driver_earnings_amount' => 0, 'payment_status' => TripPaymentStatus::PaymentFailed]);

        if ($collected > 0) {
            app(PaymentProcessor::class)->charge($rider, $card, $collected, 'AMD', "trip:{$trip->id}:capture-part", $trip);
        }

        return $trip;
    }

    public function test_nothing_owed_is_an_empty_balance(): void
    {
        $rider = $this->rider();

        $this->getJson('/api/v1/account/outstanding', $this->as($rider))->assertOk()
            ->assertExactJson(['data' => ['has_outstanding' => false, 'totals' => [], 'trips' => []]]);
        $this->getJson('/api/v1/me', $this->as($rider))->assertOk()
            ->assertJsonPath('user.rider.outstanding_balance', ['amount' => 0, 'currency' => 'AMD'])
            ->assertJsonPath('user.rider.default_payment_method', null);
    }

    public function test_a_failed_trip_payment_is_owed_minus_what_was_collected(): void
    {
        $rider = $this->rider();
        $trip = $this->failedTrip($rider, 200000, 180000);
        // Another rider's debt and the rider's own paid trip are not part of it.
        $this->failedTrip($this->rider('+37491210444'));
        $this->cardTrip($rider, $this->card($rider), 'completed', ['payment_status' => TripPaymentStatus::Paid]);

        $this->getJson('/api/v1/account/outstanding', $this->as($rider))->assertOk()->assertExactJson(['data' => [
            'has_outstanding' => true,
            'totals' => [['amount' => 20000, 'currency' => 'AMD']],
            'trips' => [['id' => $trip->id, 'public_code' => $trip->public_code, 'amount' => 20000, 'currency' => 'AMD', 'ended_at' => $trip->completed_at->toIso8601String()]],
        ]]);
    }

    public function test_a_failed_trip_whose_fare_was_collected_another_way_owes_nothing_and_blocks_nothing(): void
    {
        $rider = $this->rider();
        $trip = $this->failedTrip($rider, 150000, 150000);
        $this->card($rider, attributes: ['is_default' => true]);
        // The fare is fully covered (a manual payment recorded later, P6-T5) while the trip still says payment_failed.
        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);

        $this->getJson('/api/v1/account/outstanding', $this->as($rider))->assertOk()
            ->assertExactJson(['data' => ['has_outstanding' => false, 'totals' => [], 'trips' => []]]);
        $this->getJson('/api/v1/me', $this->as($rider))->assertJsonPath('user.rider.outstanding_balance', ['amount' => 0, 'currency' => 'AMD']);
        app(OutstandingBalance::class)->assertClear($rider);
        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => self::KEY])
            ->assertStatus(409)->assertJsonPath('code', 'nothing_outstanding');
    }

    public function test_the_balance_adds_up_every_owed_trip_per_currency(): void
    {
        $rider = $this->rider();
        // The USD trip is the oldest, so sorting by currency (not by trip) is what puts AMD first.
        $usd = $this->cardTrip($rider, $this->card($rider), 'completed', [
            'fare_currency' => 'USD', 'estimated_fare_amount' => 700, 'fare_amount' => 700, 'commission_amount' => 0, 'driver_earnings_amount' => 0,
            'payment_status' => TripPaymentStatus::PaymentFailed,
        ]);
        $a = $this->failedTrip($rider, 1000);
        $b = $this->failedTrip($rider, 2500);

        $response = $this->getJson('/api/v1/account/outstanding', $this->as($rider))->assertOk();

        $response->assertJsonPath('data.has_outstanding', true)
            // One total per currency, sorted by currency code, each the sum of that currency's trips.
            ->assertJsonPath('data.totals', [['amount' => 3500, 'currency' => 'AMD'], ['amount' => 700, 'currency' => 'USD']])
            ->assertJsonPath('data.trips.*.id', [$usd->id, $a->id, $b->id])
            ->assertJsonPath('data.trips.*.amount', [700, 1000, 2500]);
        // /me carries the first currency's total.
        $this->getJson('/api/v1/me', $this->as($rider))->assertJsonPath('user.rider.outstanding_balance', ['amount' => 3500, 'currency' => 'AMD']);
    }

    public function test_me_shows_the_balance_and_the_default_card_so_the_app_asks_for_an_update(): void
    {
        $rider = $this->rider();
        $this->failedTrip($rider, 150000);
        $default = $this->card($rider, attributes: ['is_default' => true]);

        $this->getJson('/api/v1/me', $this->as($rider))->assertOk()
            ->assertJsonPath('user.rider.outstanding_balance', ['amount' => 150000, 'currency' => 'AMD'])
            ->assertJsonPath('user.rider.default_payment_method.id', $default->id)
            ->assertJsonPath('user.rider.default_payment_method.last4', '4242');
    }

    public function test_paying_charges_the_default_card_and_clears_the_balance(): void
    {
        $rider = $this->rider();
        $trip = $this->failedTrip($rider, 150000);
        $card = $this->card($rider, attributes: ['is_default' => true]);

        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => self::KEY])->assertOk()
            ->assertExactJson(['data' => ['has_outstanding' => false, 'totals' => [], 'trips' => []]]);

        $charge = Transaction::query()->sole();
        $this->assertSame('charge', $charge->type->value);
        $this->assertSame(TransactionStatus::Succeeded, $charge->status);
        $this->assertSame(150000, $charge->amount);
        $this->assertSame($card->id, $charge->payment_method_id);
        $this->assertSame($trip->id, $charge->trip_id);
        $this->assertSame(self::KEY.':trip:'.$trip->id, $charge->idempotency_key);
        $this->assertSame('outstanding_balance', $charge->metadata['purpose']);
        $this->assertSame(TripPaymentStatus::Paid, $trip->fresh()->payment_status);
    }

    public function test_paying_twice_with_the_same_key_charges_once(): void
    {
        $rider = $this->rider();
        $this->failedTrip($rider);
        $this->card($rider, attributes: ['is_default' => true]);
        $headers = $this->as($rider) + ['Idempotency-Key' => self::KEY];

        $this->postJson('/api/v1/account/outstanding/pay', [], $headers)->assertOk();
        $this->postJson('/api/v1/account/outstanding/pay', [], $headers)->assertOk()->assertJsonPath('data.has_outstanding', false);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, $this->gateway()->moneyMovements());
        // A new key with nothing owed is told so.
        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => 'pay-outstanding-0002'])
            ->assertStatus(409)->assertJsonPath('code', 'nothing_outstanding');
    }

    public function test_one_charge_per_owed_trip_with_a_chosen_card(): void
    {
        $rider = $this->rider();
        $a = $this->failedTrip($rider, 1000);
        $b = $this->failedTrip($rider, 2500);
        $chosen = $this->card($rider);

        $this->postJson('/api/v1/account/outstanding/pay', ['payment_method_id' => $chosen->id], $this->as($rider) + ['Idempotency-Key' => self::KEY])->assertOk();

        $this->assertEquals([$a->id => 1000, $b->id => 2500], Transaction::query()->where('payment_method_id', $chosen->id)->pluck('amount', 'trip_id')->all());
        $this->assertSame(TripPaymentStatus::Paid, $a->fresh()->payment_status);
        $this->assertSame(TripPaymentStatus::Paid, $b->fresh()->payment_status);
    }

    public function test_a_declined_card_answers_402_and_the_balance_stays(): void
    {
        $rider = $this->rider();
        $trip = $this->failedTrip($rider);
        $this->card($rider, 'declined', ['is_default' => true]);

        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => self::KEY])->assertStatus(402)
            ->assertJsonPath('code', 'card_declined');

        $this->assertSame(TransactionStatus::Failed, Transaction::query()->sole()->status);
        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);
        $this->getJson('/api/v1/account/outstanding', $this->as($rider))->assertJsonPath('data.totals', [['amount' => 150000, 'currency' => 'AMD']]);
    }

    public function test_reusing_a_key_with_another_card_is_refused(): void
    {
        $rider = $this->rider();
        $this->failedTrip($rider);
        $this->card($rider, 'declined', ['is_default' => true]);
        $good = $this->card($rider);
        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => self::KEY])->assertStatus(402);

        $this->postJson('/api/v1/account/outstanding/pay', ['payment_method_id' => $good->id], $this->as($rider) + ['Idempotency-Key' => self::KEY])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        // With a new key the other card pays.
        $this->postJson('/api/v1/account/outstanding/pay', ['payment_method_id' => $good->id], $this->as($rider) + ['Idempotency-Key' => 'pay-outstanding-0003'])->assertOk();

        $this->assertSame(['failed', 'succeeded'], Transaction::query()->orderBy('id')->pluck('status')->map->value->all());
    }

    public function test_the_key_is_required_and_the_card_must_be_the_riders(): void
    {
        $rider = $this->rider();
        $this->failedTrip($rider);
        $theirs = $this->card($this->rider('+37491210444'));

        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider))->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'Send an Idempotency-Key header with this payment.']);
        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/account/outstanding/pay', ['payment_method_id' => $theirs->id], $this->as($rider) + ['Idempotency-Key' => self::KEY])
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_method_id' => 'Choose one of your saved cards.']);
        // No card at all.
        $this->postJson('/api/v1/account/outstanding/pay', [], $this->as($rider) + ['Idempotency-Key' => self::KEY])->assertStatus(409)->assertJsonPath('code', 'no_payment_method');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/account/outstanding')->assertUnauthorized();
        $this->postJson('/api/v1/account/outstanding/pay', [], ['Idempotency-Key' => self::KEY])->assertUnauthorized();
    }
}
