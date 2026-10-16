<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\PaymentException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * P6-T2 · every card movement is a transactions row written (with its idempotency key) before the gateway is called; the same key
 * never moves money twice; holds are captured or voided once; refunds stay within what was taken.
 */
class PaymentProcessorTest extends CardPaymentsTestCase
{
    private PaymentProcessor $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = app(PaymentProcessor::class);
    }

    public function test_a_charge_is_one_succeeded_row_with_the_gateway_reference(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);

        $charge = $this->payments->charge($rider, $card, 250000, 'AMD', 'key-charge-1', meta: ['purpose' => 'test']);

        $row = Transaction::query()->sole();
        $this->assertSame($charge->id, $row->id);
        $this->assertSame(TransactionType::Charge, $row->type);
        $this->assertSame(TransactionStatus::Succeeded, $row->status);
        $this->assertSame(PaymentGateway::Stripe, $row->gateway);
        $this->assertSame(250000, $row->amount);
        $this->assertSame('AMD', $row->currency);
        $this->assertSame('key-charge-1', $row->idempotency_key);
        $this->assertSame($card->id, $row->payment_method_id);
        $this->assertStringStartsWith('fake_ch_', $row->gateway_reference);
        $this->assertSame('test', $row->metadata['purpose']);
        $this->assertEquals(['object' => 'charge', 'amount' => 250000, 'currency' => 'AMD'], $row->metadata['gateway_response']);
        $this->assertSame(1, $this->gateway()->moneyMovements());
    }

    public function test_charging_twice_with_the_same_key_returns_the_original_and_charges_once(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);

        $first = $this->payments->charge($rider, $card, 250000, 'AMD', 'key-twice');
        $second = $this->payments->charge($rider, $card, 250000, 'AMD', 'key-twice');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->gateway_reference, $second->gateway_reference);
        $this->assertSame(1, Transaction::query()->count());
        // The second call never reached the gateway.
        $this->assertCount(1, $this->gateway()->calls);
        $this->assertSame(1, $this->gateway()->moneyMovements());
    }

    public function test_a_declined_charge_replayed_with_its_key_stays_one_failed_row(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider, 'declined');

        $first = $this->payments->charge($rider, $card, 1000, 'AMD', 'key-declined');
        $again = $this->payments->charge($rider, $card, 1000, 'AMD', 'key-declined');

        $this->assertSame(TransactionStatus::Failed, $first->status);
        $this->assertSame('card_declined', $first->failure_code);
        $this->assertSame('Your card was declined.', $first->failure_message);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertCount(1, $this->gateway()->calls);
    }

    public function test_the_same_key_for_a_different_payment_is_refused_and_moves_nothing(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $this->payments->charge($rider, $card, 250000, 'AMD', 'key-reused');

        foreach ([[300000, 'AMD', $card], [250000, 'USD', $card], [250000, 'AMD', $this->card($rider)]] as [$amount, $currency, $other]) {
            try {
                $this->payments->charge($rider, $other, $amount, $currency, 'key-reused');
                $this->fail('A reused key was accepted.');
            } catch (PaymentException $e) {
                $this->assertSame('idempotency_key_reused', $e->errorCode);
                $this->assertSame(409, $e->status);
            }
        }

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, $this->gateway()->moneyMovements());
    }

    public function test_the_key_is_saved_before_the_gateway_call_and_a_retry_after_a_timeout_charges_once(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        // The gateway took the money, then the answer was lost.
        $this->gateway()->timeoutNext(applied: true);

        try {
            $this->payments->charge($rider, $card, 5000, 'AMD', 'key-timeout');
            $this->fail('The timeout was swallowed.');
        } catch (PaymentGatewayException) {
        }

        $pending = Transaction::query()->sole();
        $this->assertSame(TransactionStatus::Processing, $pending->status);
        $this->assertSame('key-timeout', $pending->idempotency_key);
        $this->assertNull($pending->gateway_reference);

        $retry = $this->payments->charge($rider, $card, 5000, 'AMD', 'key-timeout');

        $this->assertSame($pending->id, $retry->id);
        $this->assertSame(TransactionStatus::Succeeded, $retry->fresh()->status);
        $this->assertNotNull($retry->fresh()->gateway_reference);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, $this->gateway()->moneyMovements());
    }

    public function test_a_timeout_before_the_gateway_acted_is_finished_by_the_retry(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $this->gateway()->timeoutNext(applied: false);

        try {
            $this->payments->charge($rider, $card, 5000, 'AMD', 'key-timeout-early');
            $this->fail('The timeout was swallowed.');
        } catch (PaymentGatewayException) {
        }

        $this->assertSame(0, $this->gateway()->moneyMovements());
        $this->payments->charge($rider, $card, 5000, 'AMD', 'key-timeout-early');

        $this->assertSame(TransactionStatus::Succeeded, Transaction::query()->sole()->status);
        $this->assertSame(1, $this->gateway()->moneyMovements());
    }

    public function test_a_second_attempt_while_the_first_holds_the_key_answers_in_progress(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        config(['payments.lock_wait_seconds' => 0]);
        $lock = Cache::lock('payments:attempt:stripe:'.hash('sha256', 'key-busy'), 60);
        $this->assertTrue($lock->get());

        try {
            $this->payments->charge($rider, $card, 5000, 'AMD', 'key-busy');
            $this->fail('The busy key was not refused.');
        } catch (PaymentException $e) {
            $this->assertSame('payment_in_progress', $e->errorCode);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_an_empty_or_too_long_key_is_refused_before_anything_happens(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);

        foreach (['', str_repeat('k', 101)] as $key) {
            try {
                $this->payments->charge($rider, $card, 5000, 'AMD', $key);
                $this->fail('A bad key was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_authorize_then_capture_takes_the_amount_from_the_hold(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);

        $hold = $this->payments->authorize($rider, $card, 180000, 'AMD', 'key-hold');
        $capture = $this->payments->capture($hold, 150000, 'key-capture');

        $this->assertSame(TransactionType::Authorization, $hold->type);
        $this->assertSame(TransactionStatus::Succeeded, $hold->status);
        $this->assertSame(TransactionType::Capture, $capture->type);
        $this->assertSame(TransactionStatus::Succeeded, $capture->status);
        $this->assertSame(150000, $capture->amount);
        $this->assertSame($hold->id, $capture->parent_transaction_id);
        $this->assertSame($card->id, $capture->payment_method_id);
        $this->assertSame(['authorize', 'capture'], array_column($this->gateway()->calls, 'op'));
        $this->assertSame(150000, $this->gateway()->calls[1]['amount']);

        // Replaying the capture returns it; capturing again with a new key is refused, and so is a void.
        $this->assertSame($capture->id, $this->payments->capture($hold, 150000, 'key-capture')->id);
        $this->assertHoldClosed(fn () => $this->payments->capture($hold, 10000, 'key-capture-2'));
        $this->assertHoldClosed(fn () => $this->payments->void($hold, 'key-void-after-capture'));
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(2, $this->gateway()->moneyMovements());
    }

    public function test_authorize_then_void_releases_the_hold_and_it_cannot_be_captured(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);

        $hold = $this->payments->authorize($rider, $card, 180000, 'AMD', 'key-hold-v');
        $void = $this->payments->void($hold, 'key-void');

        $this->assertSame(TransactionType::Void, $void->type);
        $this->assertSame(TransactionStatus::Succeeded, $void->status);
        $this->assertSame($hold->id, $void->parent_transaction_id);
        $this->assertSame(180000, $void->amount);
        $this->assertHoldClosed(fn () => $this->payments->capture($hold, 1000, 'key-capture-after-void'));
        $this->assertSame(['authorize', 'void'], array_column($this->gateway()->calls, 'op'));
    }

    public function test_a_capture_above_the_hold_is_refused_before_the_gateway(): void
    {
        $rider = $this->rider();
        $hold = $this->payments->authorize($rider, $this->card($rider), 1000, 'AMD', 'key-hold-small');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->payments->capture($hold, 1001, 'key-capture-big');
        } finally {
            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(['authorize'], array_column($this->gateway()->calls, 'op'));
        }
    }

    public function test_a_failed_capture_leaves_the_hold_capturable_again(): void
    {
        $rider = $this->rider();
        $hold = $this->payments->authorize($rider, $this->card($rider, 'capturefails'), 1000, 'AMD', 'key-hold-cf');

        $capture = $this->payments->capture($hold, 1000, 'key-capture-cf');

        $this->assertSame(TransactionStatus::Failed, $capture->status);
        $this->assertSame('card_declined', $capture->failure_code);
        // A failed capture does not count as closing the hold: a void still goes through.
        $this->assertSame(TransactionStatus::Succeeded, $this->payments->void($hold, 'key-void-cf')->status);
    }

    public function test_refunds_cannot_pass_what_was_taken(): void
    {
        $rider = $this->rider();
        $charge = $this->payments->charge($rider, $this->card($rider), 10000, 'AMD', 'key-paid');

        $first = $this->payments->refund($charge, 6000, 'key-refund-1');
        $this->assertSame(TransactionStatus::Succeeded, $first->status);
        $this->assertSame($charge->id, $first->parent_transaction_id);

        try {
            $this->payments->refund($charge, 4001, 'key-refund-2');
            $this->fail('A refund above the remaining amount was accepted.');
        } catch (PaymentException $e) {
            $this->assertSame('refund_exceeds_remaining', $e->errorCode);
        }

        $this->assertSame(TransactionStatus::Succeeded, $this->payments->refund($charge, 4000, 'key-refund-3')->status);
        $this->assertSame(3, Transaction::query()->count());
        $this->assertSame(10000, (int) Transaction::query()->where('type', 'refund')->sum('amount'));
    }

    public function test_a_hold_or_a_failed_charge_cannot_be_refunded(): void
    {
        $rider = $this->rider();
        $hold = $this->payments->authorize($rider, $this->card($rider), 10000, 'AMD', 'key-hold-r');
        $failed = $this->payments->charge($rider, $this->card($rider, 'declined'), 10000, 'AMD', 'key-failed-r');

        foreach ([$hold, $failed] as $i => $original) {
            try {
                $this->payments->refund($original, 1000, "key-refund-bad-{$i}");
                $this->fail('A refund of something not paid was accepted.');
            } catch (PaymentException $e) {
                $this->assertSame('not_refundable', $e->errorCode);
            }
        }

        $this->assertSame(0, Transaction::query()->where('type', 'refund')->count());
    }

    public function test_a_card_of_another_rider_cannot_be_charged(): void
    {
        $rider = $this->rider();
        $other = $this->card($this->rider('+37491210444'));

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->payments->charge($rider, $other, 1000, 'AMD', 'key-other-card');
        } finally {
            $this->assertSame(0, Transaction::query()->count());
        }
    }

    private function assertHoldClosed(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('A closed hold was used again.');
        } catch (PaymentException $e) {
            $this->assertSame('hold_not_open', $e->errorCode);
        }
    }
}
