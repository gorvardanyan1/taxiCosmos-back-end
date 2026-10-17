<?php

namespace App\Services\Payments;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TripPaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a rider still owes for card trips whose final payment failed (P6-T2), and paying it. It is worked out from the rows, never
 * stored: for each of the rider's trips in payment_failed, the trip's final amount (fare, or cancellation fee) minus what its
 * captures and charges collected. While anything is owed, new card trips are refused.
 */
final class OutstandingBalance
{
    public function __construct(private readonly PaymentProcessor $payments) {}

    /**
     * The trips with money owed, oldest first.
     *
     * @return Collection<int, Trip> each with an `owed` attribute (minor units of fare_currency)
     */
    public function trips(User $rider): Collection
    {
        $collected = Transaction::query()
            ->selectRaw('trip_id, sum(amount) as collected')
            ->whereIn('type', [TransactionType::Capture->value, TransactionType::Charge->value])
            ->where('status', TransactionStatus::Succeeded->value)
            ->whereNotNull('trip_id')
            ->groupBy('trip_id');

        return Trip::query()
            ->leftJoinSub($collected, 'collected', 'collected.trip_id', '=', 'trips.id')
            ->where('trips.rider_id', $rider->id)
            ->where('trips.payment_status', TripPaymentStatus::PaymentFailed->value)
            ->whereRaw('trips.fare_amount - coalesce(collected.collected, 0) > 0')
            ->orderBy('trips.id')
            ->get(['trips.*', DB::raw('(trips.fare_amount - coalesce(collected.collected, 0))::bigint as owed')]);
    }

    /**
     * The total owed per currency.
     *
     * @return list<array{amount: int, currency: string}>
     */
    public function totals(User $rider): array
    {
        return $this->trips($rider)->groupBy('fare_currency')->sortKeys()
            ->map(fn (Collection $trips, string $currency) => ['amount' => (int) $trips->sum('owed'), 'currency' => $currency])
            ->values()->all();
    }

    /** New card trips are refused while the rider owes money. */
    public function assertClear(User $rider): void
    {
        $this->trips($rider)->isEmpty() || throw PaymentException::outstandingBalance();
    }

    /**
     * Charge everything owed to the card, one charge per trip, keyed by the app's idempotency key: a retry with the same key
     * never charges a trip twice, and two payments at once run one after the other. A trip paid in full is `paid` again.
     *
     * @throws PaymentException card_declined when a charge is declined (trips charged before it stay paid)
     */
    public function pay(User $rider, PaymentMethod $card, string $idempotencyKey): void
    {
        try {
            Cache::lock('payments:outstanding:'.$rider->id, (int) config('payments.lock_seconds'))
                ->block((int) config('payments.lock_wait_seconds'), fn () => $this->payLocked($rider, $card, $idempotencyKey));
        } catch (LockTimeoutException) {
            throw PaymentException::inProgress();
        }
    }

    private function payLocked(User $rider, PaymentMethod $card, string $idempotencyKey): void
    {
        $owed = $this->trips($rider);

        if ($owed->isEmpty()) {
            // A retry of a payment that already went through is answered with the (now empty) balance, not an error.
            $replay = Transaction::query()->where('user_id', $rider->id)->where('idempotency_key', 'like', addcslashes($idempotencyKey, '%_\\').':trip:%')->exists();
            $replay || throw PaymentException::nothingOutstanding();

            return;
        }

        foreach ($owed as $trip) {
            $charge = $this->payments->charge($rider, $card, (int) $trip->owed, $trip->fare_currency, $idempotencyKey.':trip:'.$trip->id, $trip, ['purpose' => 'outstanding_balance']);

            if ($charge->status !== TransactionStatus::Succeeded) {
                throw PaymentException::declined();
            }

            Trip::query()->whereKey($trip->id)->update(['payment_status' => TripPaymentStatus::Paid->value, 'updated_at' => now()]);
        }
    }
}
