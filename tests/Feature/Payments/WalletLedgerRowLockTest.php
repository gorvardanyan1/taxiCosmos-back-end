<?php

namespace Tests\Feature\Payments;

use App\Enums\WalletReason;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payments\WalletLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves every wallet movement takes the wallet's row lock, so two simultaneous movements queue up and the second sees the balance the
 * first left: two debits of the last 1000 cannot both succeed. Uses committed data and a second real connection, like SavedPlaceRowLockTest.
 */
class WalletLedgerRowLockTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_lock_holder';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::OTHER => config('database.connections.pgsql')]);
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

    /** KEY SHARE conflicts only with the service's FOR UPDATE: not with the balance UPDATE and not with the ledger row's foreign key check. */
    private function hold(Wallet $wallet): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        $other->select('select id from wallets where id = ? for key share', [$wallet->id]);
    }

    private function funded(): Wallet
    {
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor(User::factory()->rider()->create(), 'AMD');
        $ledger->credit($wallet, 1000, WalletReason::TopUp);

        return $wallet->fresh();
    }

    /** @return array<string, array{string}> */
    public static function movements(): array
    {
        return ['a credit' => ['credit'], 'a debit' => ['debit']];
    }

    #[DataProvider('movements')]
    public function test_a_movement_waits_for_the_wallet_row_lock_and_posts_nothing_while_it_is_held(string $kind): void
    {
        $wallet = $this->funded();
        $this->hold($wallet);
        DB::statement("SET lock_timeout = '300ms'");

        try {
            $kind === 'credit'
                ? app(WalletLedger::class)->credit($wallet, 500, WalletReason::TopUp)
                : app(WalletLedger::class)->debit($wallet, 500, WalletReason::TripPayment);
            $this->fail("{$kind} must wait for the wallet row lock held by another transaction.");
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        }

        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame(1000, $wallet->fresh()->balance);
    }

    public function test_the_movement_is_posted_once_the_lock_is_released(): void
    {
        $wallet = $this->funded();
        $this->hold($wallet);
        DB::connection(self::OTHER)->rollBack();

        app(WalletLedger::class)->debit($wallet, 1000, WalletReason::TripPayment);

        $this->assertSame(0, $wallet->fresh()->balance);
        $this->assertSame(2, WalletTransaction::query()->count());
        DB::connection(self::OTHER)->beginTransaction(); // balance tearDown's rollBack
    }
}
