<?php

namespace Tests\Feature\Payments;

use App\Enums\WalletReason;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\WalletLedger;
use App\Services\Payments\WalletReconciler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * P6-T1 · the nightly reconciliation: every cached wallet balance is re-checked against its ledger, and a mismatch is alerted, not hidden.
 */
class WalletReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(WalletLedger::class);
    }

    private function fundedWallet(int $balance = 7000, string $currency = 'AMD'): Wallet
    {
        $wallet = $this->ledger->walletFor(User::factory()->rider()->create(), $currency);
        $this->ledger->credit($wallet, $balance + 1000, WalletReason::TopUp);
        $this->ledger->debit($wallet, 1000, WalletReason::TripPayment, 'trip', random_int(1, 1000000));

        return $wallet->fresh();
    }

    /** What a bug or a hand edit would do: the cached balance moves without a ledger row. */
    private function corrupt(Wallet $wallet, int $to): void
    {
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => $to]);
    }

    public function test_wallets_that_match_their_ledger_report_nothing_and_log_nothing(): void
    {
        Log::spy();
        $this->fundedWallet(7000);
        $this->fundedWallet(1200, 'USD');
        $empty = $this->ledger->walletFor(User::factory()->rider()->create(), 'AMD');

        $this->assertSame([], app(WalletReconciler::class)->reconcile());
        $this->assertSame(0, $empty->fresh()->balance);
        Log::shouldNotHaveReceived('critical');
        $this->artisan('wallets:reconcile')->expectsOutput('Every wallet balance matches its ledger.')->assertSuccessful();
    }

    public function test_a_wallet_whose_balance_drifted_is_found_with_both_numbers_and_alerted_at_critical_level(): void
    {
        Log::spy();
        $good = $this->fundedWallet(7000);
        $bad = $this->fundedWallet(5000);
        $this->corrupt($bad, 9000);

        $found = app(WalletReconciler::class)->reconcile();

        $this->assertSame([['wallet_id' => $bad->id, 'user_id' => $bad->user_id, 'currency' => 'AMD', 'cached' => 9000, 'ledger' => 5000, 'difference' => 4000]], $found);
        Log::shouldHaveReceived('critical')->once()->with('A wallet balance does not match its ledger.', ['wallet_id' => $bad->id, 'user_id' => $bad->user_id, 'currency' => 'AMD', 'cached' => 9000, 'ledger' => 5000, 'difference' => 4000]);
        $this->assertSame(7000, $good->fresh()->balance);
    }

    public function test_a_balance_that_is_too_low_and_a_balance_on_a_wallet_with_no_ledger_are_found_too(): void
    {
        $short = $this->fundedWallet(3000);
        $this->corrupt($short, 2500);
        $phantom = $this->ledger->walletFor(User::factory()->rider()->create(), 'AMD');
        $this->corrupt($phantom, 100);

        $found = collect(app(WalletReconciler::class)->mismatches())->keyBy('wallet_id');

        $this->assertSame(-500, $found[$short->id]['difference']);
        $this->assertSame([100, 0, 100], [$found[$phantom->id]['cached'], $found[$phantom->id]['ledger'], $found[$phantom->id]['difference']]);
        $this->assertCount(2, $found);
    }

    public function test_the_check_reports_and_never_changes_a_balance_or_the_ledger(): void
    {
        $wallet = $this->fundedWallet(4000);
        $this->corrupt($wallet, 1);
        $rows = DB::table('wallet_transactions')->count();

        app(WalletReconciler::class)->reconcile();
        app(WalletReconciler::class)->reconcile();

        $this->assertSame(1, $wallet->fresh()->balance, 'left as found, for a person to look at');
        $this->assertSame($rows, DB::table('wallet_transactions')->count());
    }

    public function test_the_command_fails_and_lists_the_wallets_that_do_not_match(): void
    {
        $bad = $this->fundedWallet(5000);
        $this->corrupt($bad, 4000);

        $this->artisan('wallets:reconcile')->expectsOutputToContain('1 wallet balance(s) do not match their ledger.')->assertFailed();
    }

    public function test_every_wallet_is_checked_not_just_a_page_of_them(): void
    {
        $bad = [];
        foreach (range(1, 30) as $i) {
            $wallet = $this->fundedWallet(1000 + $i);
            if ($i % 10 === 0) {
                $this->corrupt($wallet, 1);
                $bad[] = $wallet->id;
            }
        }

        $this->assertSame($bad, array_column(app(WalletReconciler::class)->mismatches(), 'wallet_id'));
    }

    public function test_the_job_is_scheduled_nightly_without_overlap_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'wallets:reconcile'));

        $this->assertNotNull($event, 'wallets:reconcile is scheduled');
        $this->assertSame('20 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
