<?php

namespace Tests\Feature\Payments;

use App\Enums\TransactionStatus;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use App\Services\Payments\OutstandingBalance;
use App\Services\Payments\PaymentProcessor;
use App\Services\Payments\SavedCards;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6-T2 · proves the card money paths take their locks, so two simultaneous requests queue up instead of both acting: a refund and a
 * capture/void lock the row they act on, changing the default card locks the rider, and paying the balance (and saving a card) holds a
 * per-rider lock. Row locks use committed data and a second real connection, like WalletLedgerRowLockTest.
 */
class PaymentLocksTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_lock_holder';

    private PaymentProcessor $payments;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.'.self::OTHER => config('database.connections.pgsql'),
            'payments.driver' => 'fake',
            'payments.gateway' => 'stripe',
            'payments.lock_wait_seconds' => 0,
        ]);
        $this->payments = app(PaymentProcessor::class);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::purge(self::OTHER);
        DB::statement('RESET lock_timeout');
        // Clear the committed rows so they never leak into the RefreshDatabase tests that run after this class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    /** KEY SHARE conflicts only with the service's FOR UPDATE, not with the plain updates and inserts the service makes. */
    private function hold(string $table, int $id): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $other->select("select id from {$table} where id = ? for key share", [$id]);
    }

    private function release(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::connection(self::OTHER)->beginTransaction(); // balance tearDown's rollBack
    }

    private function rider(): User
    {
        return User::factory()->rider()->withPhone('+37491210443')->create();
    }

    private function card(User $rider, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::factory()->create(['user_id' => $rider->id, 'gateway_payment_method_id' => 'fake_pm_ok_'.uniqid(), ...$attributes]);
    }

    private function assertLockedOut(callable $attempt): void
    {
        DB::statement("SET lock_timeout = '300ms'");

        try {
            $attempt();
            $this->fail('The call must wait for the row lock held by another transaction.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function test_a_refund_waits_for_the_lock_on_the_charge_and_records_nothing_while_it_is_held(): void
    {
        $rider = $this->rider();
        $charge = $this->payments->charge($rider, $this->card($rider), 10000, 'AMD', 'lock-charge');
        $this->hold('transactions', $charge->id);

        $this->assertLockedOut(fn () => $this->payments->refund($charge, 6000, 'lock-refund-1'));

        $this->assertSame(0, Transaction::query()->where('type', 'refund')->count());

        // Once the other request is done, the refund goes through, and a second one for the rest cannot pass what is left.
        $this->release();
        $this->assertSame(TransactionStatus::Succeeded, $this->payments->refund($charge, 6000, 'lock-refund-1')->status);
        $this->expectException(PaymentException::class);
        $this->payments->refund($charge, 6000, 'lock-refund-2');
    }

    public function test_a_capture_and_a_void_wait_for_the_lock_on_the_hold_and_record_nothing_while_it_is_held(): void
    {
        $rider = $this->rider();
        $hold = $this->payments->authorize($rider, $this->card($rider), 18000, 'AMD', 'lock-hold');
        $this->hold('transactions', $hold->id);

        $this->assertLockedOut(fn () => $this->payments->capture($hold, 15000, 'lock-capture'));
        $this->assertLockedOut(fn () => $this->payments->void($hold, 'lock-void'));

        $this->assertSame(0, Transaction::query()->whereIn('type', ['capture', 'void'])->count());

        $this->release();
        $this->assertSame(TransactionStatus::Succeeded, $this->payments->capture($hold, 15000, 'lock-capture')->status);
    }

    public function test_changing_or_removing_a_card_waits_for_the_lock_on_the_rider(): void
    {
        $rider = $this->rider();
        $old = $this->card($rider, ['is_default' => true]);
        $card = $this->card($rider);
        $this->hold('users', $rider->id);
        $cards = app(SavedCards::class);

        $this->assertLockedOut(fn () => $cards->makeDefault($rider, $card));
        $this->assertLockedOut(fn () => $cards->remove($rider, $old));

        $this->assertTrue($old->fresh()->is_default);
        $this->assertFalse($card->fresh()->is_default);
        $this->assertNotSoftDeleted($old);

        $this->release();
        $cards->makeDefault($rider, $card);
        $this->assertSame([$card->id], PaymentMethod::query()->where('is_default', true)->pluck('id')->all());
    }

    public function test_saving_a_card_waits_for_the_lock_on_the_rider_and_saves_nothing_while_it_is_held(): void
    {
        $rider = $this->rider();
        $this->hold('users', $rider->id);

        $this->assertLockedOut(fn () => app(SavedCards::class)->add($rider, 'tok_visa', true));

        $this->assertSame(0, PaymentMethod::query()->count());

        // Once the other request is done, the same call saves the card as the default.
        $this->release();
        $card = app(SavedCards::class)->add($rider, 'tok_visa', true)['card'];
        $this->assertTrue($card->is_default);
        $this->assertSame(1, PaymentMethod::query()->count());
    }

    public function test_a_second_payment_of_the_balance_while_the_first_is_running_charges_nothing(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider, ['is_default' => true]);
        $trip = Trip::factory()->completed()->create(['rider_id' => $rider->id, 'payment_method' => TripPaymentMethod::Card, 'payment_method_id' => $card->id, 'payment_status' => TripPaymentStatus::PaymentFailed]);
        $lock = Cache::lock('payments:outstanding:'.$rider->id, 60);
        $this->assertTrue($lock->get());

        try {
            app(OutstandingBalance::class)->pay($rider, $card, 'lock-pay-second');
            $this->fail('A second payment ran while the first held the lock.');
        } catch (PaymentException $e) {
            $this->assertSame('payment_in_progress', $e->errorCode);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(TripPaymentStatus::PaymentFailed, $trip->fresh()->payment_status);

        app(OutstandingBalance::class)->pay($rider, $card, 'lock-pay-second');
        $this->assertSame(1, Transaction::query()->where('type', 'charge')->count());
    }

    public function test_saving_a_card_while_another_save_is_running_saves_nothing(): void
    {
        $rider = $this->rider();
        $lock = Cache::lock('payments:cards:'.$rider->id, 60);
        $this->assertTrue($lock->get());

        try {
            app(SavedCards::class)->add($rider, 'tok_visa', false);
            $this->fail('A second save ran while the first held the lock.');
        } catch (PaymentException $e) {
            $this->assertSame('payment_in_progress', $e->errorCode);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, PaymentMethod::query()->count());
    }
}
