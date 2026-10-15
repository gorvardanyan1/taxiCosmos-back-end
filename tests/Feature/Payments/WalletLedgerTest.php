<?php

namespace Tests\Feature\Payments;

use App\Enums\WalletEntryType;
use App\Enums\WalletReason;
use App\Exceptions\WalletException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payments\WalletLedger;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * P6-T1 · the wallet ledger: append-only, and the cached balance moves only with the ledger row, in the same transaction.
 */
class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedger $ledger;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(WalletLedger::class);
        $this->rider = User::factory()->rider()->create();
    }

    private function wallet(string $currency = 'AMD'): Wallet
    {
        return $this->ledger->walletFor($this->rider, $currency);
    }

    /** The balance the ledger says, straight from the rows. */
    private function ledgerTotal(Wallet $wallet): int
    {
        return (int) DB::selectOne("SELECT COALESCE(SUM(CASE type WHEN 'credit' THEN amount ELSE -amount END), 0) AS total FROM wallet_transactions WHERE wallet_id = ?", [$wallet->id])->total;
    }

    public function test_a_wallet_is_created_empty_once_per_user_and_currency(): void
    {
        $first = $this->wallet();
        $again = $this->wallet();
        $usd = $this->wallet('USD');

        $this->assertSame($first->id, $again->id);
        $this->assertNotSame($first->id, $usd->id);
        $this->assertSame([0, 'AMD', $this->rider->id], [$first->balance, $first->currency, $first->user_id]);
        $this->assertSame(2, Wallet::query()->where('user_id', $this->rider->id)->count());
    }

    public function test_a_credit_inserts_the_ledger_row_and_moves_the_balance_in_one_step(): void
    {
        $wallet = $this->wallet();
        $admin = User::factory()->create();

        $entry = $this->ledger->credit($wallet, 5000, WalletReason::GoodwillCredit, createdBy: $admin);

        $row = WalletTransaction::query()->sole();
        $this->assertSame([$wallet->id, WalletEntryType::Credit, 5000, WalletReason::GoodwillCredit, $admin->id, null, null], [$row->wallet_id, $row->type, $row->amount, $row->reason, $row->created_by, $row->reference_type, $row->reference_id]);
        $this->assertSame($entry->id, $row->id);
        $this->assertSame(5000, $wallet->fresh()->balance);
        $this->assertSame(5000, $wallet->balance, 'the wallet passed in is refreshed too');
        $this->assertSame(5000, $this->ledgerTotal($wallet));
    }

    public function test_a_debit_lowers_the_balance_and_the_ledger_always_adds_up(): void
    {
        $wallet = $this->wallet();
        $this->ledger->credit($wallet, 10000, WalletReason::TopUp);
        $this->ledger->debit($wallet, 3500, WalletReason::TripPayment, 'trip', 42);
        $this->ledger->debit($wallet, 1500, WalletReason::Adjustment);
        $this->ledger->credit($wallet, 200, WalletReason::RefundToWallet, 'transaction', 7);

        $this->assertSame(5200, $wallet->fresh()->balance);
        $this->assertSame(5200, $this->ledgerTotal($wallet));
        $this->assertSame(4, WalletTransaction::query()->count());
    }

    public function test_a_debit_larger_than_the_balance_is_refused_and_changes_nothing(): void
    {
        $wallet = $this->wallet();
        $this->ledger->credit($wallet, 1000, WalletReason::TopUp);

        try {
            $this->ledger->debit($wallet, 1001, WalletReason::TripPayment, 'trip', 1);
            $this->fail('An overdraft must be refused.');
        } catch (WalletException $e) {
            $this->assertSame('The wallet balance is not enough.', $e->getMessage());
        }

        $this->assertSame(1000, $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()->count());
        // The exact balance can be spent.
        $this->ledger->debit($wallet, 1000, WalletReason::TripPayment, 'trip', 2);
        $this->assertSame(0, $wallet->fresh()->balance);
    }

    public function test_the_same_cause_posted_twice_moves_money_once(): void
    {
        $wallet = $this->wallet();

        $first = $this->ledger->credit($wallet, 2500, WalletReason::RefundToWallet, 'transaction', 99);
        $again = $this->ledger->credit($wallet, 2500, WalletReason::RefundToWallet, 'transaction', 99);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame(2500, $wallet->fresh()->balance);
        // The same id for another kind of cause, or the opposite direction, is a different movement.
        $this->ledger->credit($wallet, 100, WalletReason::RefundToWallet, 'trip', 99);
        $this->ledger->debit($wallet, 100, WalletReason::TripPayment, 'transaction', 99);
        $this->assertSame(2500, $wallet->fresh()->balance);
        $this->assertSame(3, WalletTransaction::query()->count());
    }

    public function test_the_database_refuses_a_second_entry_for_the_same_cause(): void
    {
        $wallet = $this->wallet();
        $this->ledger->credit($wallet, 100, WalletReason::RefundToWallet, 'transaction', 5);

        try {
            DB::transaction(fn () => DB::table('wallet_transactions')->insert(['wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 100, 'reason' => 'refund_to_wallet', 'reference_type' => 'transaction', 'reference_id' => 5, 'created_at' => now()]));
            $this->fail('The unique key must refuse it.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }
    }

    public function test_an_amount_must_be_positive_and_a_reference_needs_both_parts(): void
    {
        $wallet = $this->wallet();

        foreach ([0, -5] as $amount) {
            try {
                $this->ledger->credit($wallet, $amount, WalletReason::TopUp);
                $this->fail("{$amount} must be refused");
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->ledger->credit($wallet, 100, WalletReason::TopUp, 'trip', null);
        } finally {
            $this->assertSame(0, WalletTransaction::query()->count());
        }
    }

    public function test_a_balance_that_would_overflow_is_refused(): void
    {
        $wallet = $this->wallet();
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => PHP_INT_MAX - 10]);

        $this->expectException(WalletException::class);
        try {
            $this->ledger->credit($wallet->fresh(), 11, WalletReason::TopUp);
        } finally {
            $this->assertSame(0, WalletTransaction::query()->count());
        }
    }

    // ---- append-only ------------------------------------------------------------------------------------------------------

    public function test_a_ledger_entry_cannot_be_changed_or_removed_through_the_model(): void
    {
        $entry = $this->ledger->credit($this->wallet(), 1000, WalletReason::TopUp);

        foreach ([fn () => $entry->forceFill(['amount' => 1])->save(), fn () => $entry->delete(), fn () => WalletTransaction::query()->find($entry->id)->forceFill(['amount' => 5])->save(), fn () => WalletTransaction::query()->find($entry->id)->forceDelete()] as $attempt) {
            try {
                $attempt();
                $this->fail('The model must refuse to change or remove an entry.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('never', $e->getMessage());
            }
        }

        $this->assertSame(1000, WalletTransaction::query()->findOrFail($entry->id)->amount);
        // Mass assignment of a ledger field is not possible either.
        $this->expectException(MassAssignmentException::class);
        WalletTransaction::query()->find($entry->id)->update(['amount' => 5]);
    }

    public function test_the_database_refuses_to_change_or_remove_an_entry_whatever_code_tries(): void
    {
        $wallet = $this->wallet();
        $entry = $this->ledger->credit($wallet, 1000, WalletReason::TopUp);

        foreach (['update wallet_transactions set amount = 1 where id = ?', 'delete from wallet_transactions where id = ?', 'update wallet_transactions set reason = \'adjustment\' where id = ?'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql, [$entry->id]));
                $this->fail("{$sql} must be refused");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        try {
            DB::transaction(fn () => DB::statement('delete from wallet_transactions'));
            $this->fail('A bulk delete must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(1000, (int) DB::table('wallet_transactions')->where('id', $entry->id)->value('amount'));
        $this->assertSame(1000, $wallet->fresh()->balance);
    }

    public function test_no_application_code_updates_or_deletes_the_ledger(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->in([base_path('app'), base_path('routes'), base_path('database/seeders')])->name('*.php') as $file) {
            $code = $file->getContents();
            $touches = preg_match('/WalletTransaction::[a-z]+\(\)?->?\s*(?:[^;]*?)->(update|delete|forceDelete|truncate|increment|decrement)\(/is', $code)
                || preg_match('/table\(\s*[\'"]wallet_transactions[\'"]\s*\)[^;]*->(update|delete|truncate|increment|decrement)\(/is', $code)
                || preg_match('/(UPDATE|DELETE\s+FROM)\s+wallet_transactions/i', $code)
                || preg_match('/\$(?:entry|walletTransaction|ledgerEntry)->(update|delete|forceDelete)\(/', $code);

            if ($touches) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Wallet ledger rows are only ever inserted.');
    }

    // ---- money safety -----------------------------------------------------------------------------------------------------

    public function test_a_wallet_moves_only_through_the_ledger_not_by_mass_assignment(): void
    {
        $wallet = $this->wallet();

        try {
            $wallet->fill(['balance' => 999999]);
            $this->fail('balance must not be mass-assignable');
        } catch (MassAssignmentException) {
        }

        $this->assertSame(0, $wallet->balance);
        $this->assertSame(0, $wallet->fresh()->balance);
    }

    public function test_ledger_rows_use_integer_minor_units(): void
    {
        $wallet = $this->wallet('USD');

        $this->ledger->credit($wallet, 1999, WalletReason::TopUp);

        $this->assertSame('int8', \Schema::getColumnType('wallet_transactions', 'amount'));
        $this->assertSame(1999, WalletTransaction::query()->sole()->amount);
    }
}
