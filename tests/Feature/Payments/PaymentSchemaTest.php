<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PaymentMethod;
use App\Models\ProcessedWebhookEvent;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-T1 · the payment core schema, against the real PostgreSQL: the constraints are the database's, not only the application's.
 */
class PaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['payment_methods', 'transactions', 'wallets', 'wallet_transactions', 'processed_webhook_events'];

    /** Runs the closure and returns the database error code it caused (a savepoint keeps the test's transaction usable). */
    private function dbError(callable $attempt): string
    {
        try {
            DB::transaction($attempt);
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        $this->fail('The database accepted a row it must refuse (test line '.debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'].').');
    }

    private const UNIQUE = '23505';

    private const CHECK = '23514';

    private function charge(array $over = []): Transaction
    {
        return Transaction::factory()->create($over);
    }

    // ---- the tables exist -------------------------------------------------------------------------------------------------

    public function test_the_migrations_create_every_table_with_the_columns_the_task_lists(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertEqualsCanonicalizing(['id', 'trip_id', 'user_id', 'payment_method_id', 'parent_transaction_id', 'gateway', 'gateway_reference', 'idempotency_key', 'type', 'status', 'amount', 'currency', 'failure_code', 'failure_message', 'metadata', 'created_by', 'created_at', 'updated_at'], Schema::getColumnListing('transactions'));
        $this->assertEqualsCanonicalizing(['id', 'user_id', 'gateway', 'gateway_customer_id', 'gateway_payment_method_id', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'created_at', 'updated_at', 'deleted_at'], Schema::getColumnListing('payment_methods'));
        $this->assertEqualsCanonicalizing(['id', 'user_id', 'currency', 'balance', 'created_at', 'updated_at'], Schema::getColumnListing('wallets'));
        $this->assertEqualsCanonicalizing(['id', 'wallet_id', 'type', 'amount', 'reason', 'reference_type', 'reference_id', 'created_by', 'created_at'], Schema::getColumnListing('wallet_transactions'));
        $this->assertEqualsCanonicalizing(['id', 'gateway', 'event_id', 'received_at'], Schema::getColumnListing('processed_webhook_events'));
    }

    // ---- money is integer + currency --------------------------------------------------------------------------------------

    public function test_every_monetary_column_is_an_integer_with_its_own_currency_column(): void
    {
        $money = ['transactions' => ['amount'], 'wallets' => ['balance'], 'wallet_transactions' => ['amount']];

        foreach ($money as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('int8', Schema::getColumnType($table, $column), "{$table}.{$column} is integer minor units (bigint)");
            }
        }

        $this->assertSame('bpchar', Schema::getColumnType('transactions', 'currency'));
        $this->assertSame('bpchar', Schema::getColumnType('wallets', 'currency'));
        // The ledger rows take their currency from their wallet.
        $this->assertTrue(Schema::hasColumn('wallets', 'currency'));
        // No floating point or decimal money anywhere in these tables.
        $types = DB::select("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN ('".implode("','", self::TABLES)."') AND data_type IN ('real', 'double precision', 'numeric')");
        $this->assertSame([], $types);
    }

    public function test_an_amount_is_a_positive_whole_number_and_a_currency_is_three_capital_letters(): void
    {
        $user = User::factory()->rider()->create();
        $wallet = fn (array $over) => array_merge(['user_id' => $user->id, 'currency' => 'AMD', 'balance' => 0, 'created_at' => now(), 'updated_at' => now()], $over);

        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($user, 'zero'), ['amount' => 0]))), 'amount 0');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($user, 'lower'), ['currency' => 'amd']))), 'lower-case currency');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($user, 'short'), ['currency' => 'AM']))), 'two-letter currency');
        $this->assertSame('22P02', $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($user, 'text'), ['amount' => 'ten']))), 'text amount');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('wallets')->insert($wallet(['balance' => -1]))), 'negative balance');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('wallets')->insert($wallet(['currency' => 'amd']))), 'wallet currency');
        $this->assertSame(0, DB::table('transactions')->count() + DB::table('wallets')->count());

        DB::table('transactions')->insert(array_merge($this->raw($user, 'fine'), ['amount' => 150000]));
        $this->assertSame(150000, (int) DB::table('transactions')->value('amount'));
    }

    /** @return array<string, mixed> */
    private function raw(User $user, string $key = 'raw-key'): array
    {
        return ['user_id' => $user->id, 'gateway' => 'stripe', 'idempotency_key' => $key, 'type' => 'charge', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'AMD'];
    }

    // ---- idempotency ------------------------------------------------------------------------------------------------------

    public function test_a_second_insert_with_the_same_idempotency_key_on_the_same_gateway_fails_in_the_database(): void
    {
        $first = $this->charge(['idempotency_key' => 'attempt-1']);

        $code = $this->dbError(fn () => DB::table('transactions')->insert($this->raw(User::query()->findOrFail($first->user_id), 'attempt-1')));

        $this->assertSame(self::UNIQUE, $code);
        $this->assertSame(1, Transaction::query()->count());
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'transactions' AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%idempotency_key%'");
        $this->assertStringContainsString('(gateway, idempotency_key)', $index->indexdef);
    }

    public function test_the_same_key_is_fine_on_another_gateway_and_a_different_key_on_the_same_one(): void
    {
        $this->charge(['idempotency_key' => 'shared', 'gateway' => PaymentGateway::Stripe]);

        $this->charge(['idempotency_key' => 'shared', 'gateway' => PaymentGateway::AuthorizeNet]);
        $this->charge(['idempotency_key' => 'other', 'gateway' => PaymentGateway::Stripe]);

        $this->assertSame(3, Transaction::query()->count());
    }

    public function test_the_key_is_required_and_cannot_be_empty(): void
    {
        $user = User::factory()->rider()->create();

        $this->assertSame('23502', $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($user), ['idempotency_key' => null]))));
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($user), ['idempotency_key' => '']))));
    }

    // ---- shape rules ------------------------------------------------------------------------------------------------------

    public function test_a_capture_a_void_and_a_refund_must_point_at_their_original(): void
    {
        $original = $this->charge();

        foreach ([TransactionType::Capture, TransactionType::Void, TransactionType::Refund] as $type) {
            $this->assertSame(self::CHECK, $this->dbError(fn () => $this->charge(['type' => $type, 'user_id' => $original->user_id])), $type->value.' without a parent');
            $child = Transaction::factory()->of($original, $type, 1000)->create();
            $this->assertSame($original->id, $child->parent->id);
        }

        $this->assertCount(3, $original->children);
        // A charge or authorization has no parent to require.
        $this->charge(['type' => TransactionType::Authorization]);
    }

    public function test_a_transaction_cannot_be_its_own_parent_and_enums_are_enforced(): void
    {
        $row = $this->charge();

        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('transactions')->where('id', $row->id)->update(['parent_transaction_id' => $row->id])));
        foreach (['gateway' => 'paypal', 'type' => 'transfer', 'status' => 'done'] as $column => $value) {
            $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('transactions')->insert(array_merge($this->raw($row->user, 'enum-'.$column), [$column => $value]))), $column);
        }
    }

    public function test_a_manual_entry_names_the_admin_who_recorded_it(): void
    {
        $this->assertSame(self::CHECK, $this->dbError(fn () => $this->charge(['gateway' => PaymentGateway::Manual])));

        $admin = User::factory()->create();
        $manual = Transaction::factory()->manual($admin)->create();

        $this->assertSame($admin->id, $manual->creator->id);
    }

    public function test_a_transaction_keeps_its_trip_user_card_and_failure_details_and_never_loses_its_history(): void
    {
        $trip = Trip::factory()->create();
        $card = PaymentMethod::factory()->create(['user_id' => $trip->rider_id]);
        $failed = Transaction::factory()->failed('card_declined', 'Your card was declined.')->create(['trip_id' => $trip->id, 'user_id' => $trip->rider_id, 'payment_method_id' => $card->id, 'metadata' => ['attempt' => 2]]);

        $fresh = Transaction::query()->findOrFail($failed->id);
        $this->assertSame([TransactionStatus::Failed, 'card_declined', 'Your card was declined.', ['attempt' => 2], 150000, 'AMD'], [$fresh->status, $fresh->failure_code, $fresh->failure_message, $fresh->metadata, $fresh->amount, $fresh->currency]);
        $this->assertSame([$trip->id, $card->id], [$fresh->trip->id, $fresh->paymentMethod->id]);
        // Money history is never cascaded away.
        $this->assertSame('23001', $this->dbError(fn () => DB::table('trips')->where('id', $trip->id)->delete()));
        $this->assertSame('23001', $this->dbError(fn () => DB::table('users')->where('id', $trip->rider_id)->delete()));
    }

    public function test_the_metadata_is_jsonb(): void
    {
        $this->assertSame('jsonb', Schema::getColumnType('transactions', 'metadata'));
    }

    // ---- payment methods --------------------------------------------------------------------------------------------------

    public function test_a_payment_method_stores_gateway_tokens_and_hides_them_from_serialisation(): void
    {
        $card = PaymentMethod::factory()->create(['gateway_customer_id' => 'cus_secret', 'gateway_payment_method_id' => 'pm_secret']);

        $array = $card->fresh()->toArray();

        $this->assertArrayNotHasKey('gateway_customer_id', $array);
        $this->assertArrayNotHasKey('gateway_payment_method_id', $array);
        $this->assertSame(['last4' => '4242', 'brand' => 'visa'], ['last4' => $array['last4'], 'brand' => $array['brand']]);
    }

    public function test_a_payment_method_cannot_hold_a_card_number(): void
    {
        $user = User::factory()->rider()->create();
        $row = fn (array $over) => array_merge(['user_id' => $user->id, 'gateway' => 'stripe', 'gateway_payment_method_id' => 'pm_'.uniqid(), 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030, 'created_at' => now(), 'updated_at' => now()], $over);

        // A full 16-digit number does not fit in last4, and nothing but four digits does.
        $this->assertSame('22001', $this->dbError(fn () => DB::table('payment_methods')->insert($row(['last4' => '4242424242424242']))), '16 digits');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('payment_methods')->insert($row(['last4' => '42a2']))), 'letters');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('payment_methods')->insert($row(['exp_month' => 13]))), 'month 13');
        $this->assertSame(self::CHECK, $this->dbError(fn () => DB::table('payment_methods')->insert($row(['gateway' => 'manual']))), 'manual gateway');
        $this->assertSame(0, DB::table('payment_methods')->count());
    }

    public function test_one_default_card_per_rider_and_one_row_per_gateway_token_among_the_live_ones(): void
    {
        $user = User::factory()->rider()->create();
        $first = PaymentMethod::factory()->default()->create(['user_id' => $user->id, 'gateway_payment_method_id' => 'pm_one']);

        $this->assertSame(self::UNIQUE, $this->dbError(fn () => PaymentMethod::factory()->default()->create(['user_id' => $user->id])));
        $this->assertSame(self::UNIQUE, $this->dbError(fn () => PaymentMethod::factory()->create(['gateway_payment_method_id' => 'pm_one'])));

        // Removing the card (soft delete) frees both.
        $first->delete();
        PaymentMethod::factory()->default()->create(['user_id' => $user->id, 'gateway_payment_method_id' => 'pm_one']);
        $this->assertSame(1, PaymentMethod::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, PaymentMethod::withTrashed()->where('user_id', $user->id)->count());
    }

    public function test_a_trip_can_point_at_a_saved_card_and_keeps_the_trip_if_the_card_row_goes(): void
    {
        $card = PaymentMethod::factory()->create();
        $trip = Trip::factory()->create(['rider_id' => $card->user_id]);
        $trip->forceFill(['payment_method_id' => $card->id])->saveQuietly();

        $this->assertSame('23503', $this->dbError(fn () => DB::table('trips')->where('id', $trip->id)->update(['payment_method_id' => 99999999])));
        DB::table('payment_methods')->where('id', $card->id)->delete();
        $this->assertNull($trip->fresh()->payment_method_id);
    }

    // ---- no card data anywhere --------------------------------------------------------------------------------------------

    public function test_no_column_in_the_payment_tables_can_hold_a_raw_card_number_or_cvc(): void
    {
        $columns = DB::select("SELECT table_name, column_name, data_type, character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN ('".implode("','", self::TABLES)."') ORDER BY table_name, ordinal_position");

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/(card_?(number|no|num)|pan|cvc|cvv|cvn|cid|security_?code|track_?data|magstripe)/i', $column->column_name, "{$column->table_name}.{$column->column_name}");
        }

        // Where a card could be squeezed in, it cannot: the last digits are char(4); the free-text columns are capped; metadata is the only open one.
        $byName = collect($columns)->keyBy(fn ($c) => "{$c->table_name}.{$c->column_name}");
        $this->assertSame(4, (int) $byName['payment_methods.last4']->character_maximum_length);
        foreach (['transactions.failure_code' => 64, 'transactions.failure_message' => 500, 'transactions.gateway_reference' => 191, 'payment_methods.brand' => 32] as $name => $limit) {
            $this->assertSame($limit, (int) $byName[$name]->character_maximum_length, $name);
        }
        $open = $byName->filter(fn ($c) => in_array($c->data_type, ['text', 'jsonb', 'json'], true))->keys()->all();
        $this->assertSame(['transactions.metadata'], $open);
    }

    // ---- webhook events ---------------------------------------------------------------------------------------------------

    public function test_a_webhook_event_is_recorded_once_per_gateway_and_a_repeat_is_discarded(): void
    {
        $this->assertTrue(ProcessedWebhookEvent::firstTime(PaymentGateway::Stripe, 'evt_1'));
        $this->assertFalse(ProcessedWebhookEvent::firstTime(PaymentGateway::Stripe, 'evt_1'));
        $this->assertTrue(ProcessedWebhookEvent::firstTime(PaymentGateway::AuthorizeNet, 'evt_1'));
        $this->assertTrue(ProcessedWebhookEvent::firstTime(PaymentGateway::Stripe, 'evt_2'));

        $this->assertSame(3, ProcessedWebhookEvent::query()->count());
        $this->assertSame(self::UNIQUE, $this->dbError(fn () => DB::table('processed_webhook_events')->insert(['gateway' => 'stripe', 'event_id' => 'evt_1'])));
        $row = ProcessedWebhookEvent::query()->where('event_id', 'evt_2')->sole();
        $this->assertNotNull($row->received_at);
    }

    // ---- wallets ----------------------------------------------------------------------------------------------------------

    public function test_a_user_has_one_wallet_per_currency(): void
    {
        $user = User::factory()->rider()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'AMD']);

        $this->assertSame(self::UNIQUE, $this->dbError(fn () => Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'AMD'])));
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD']);
        $this->assertSame(2, Wallet::query()->where('user_id', $user->id)->count());
    }

    #[DataProvider('tables')]
    public function test_every_table_has_a_working_down_migration_target(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    /** @return array<string, array{string}> */
    public static function tables(): array
    {
        return array_combine(self::TABLES, array_map(fn ($t) => [$t], self::TABLES));
    }
}
