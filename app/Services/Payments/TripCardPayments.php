<?php

namespace App\Services\Payments;

use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Exceptions\PaymentException;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Trip;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The money side of a card trip (P6-T2). Cash trips never come here and make no gateway call (P11-T3).
 *
 *  - Requested: hold the estimated fare plus a buffer (payments.hold_buffer_percent, 20%). Refused while the rider owes money.
 *  - Completed: capture the final fare from the hold; a fare above the hold takes the difference as a separate charge.
 *  - Cancelled: with a fee (the trip's fare_amount), capture the fee; without one, release the hold.
 *  - A payment that fails at the end makes the trip payment_failed: the amount becomes the rider's outstanding balance.
 *  - Holds about to lapse on a trip that is still running are renewed (payments:refresh-holds).
 *
 * Every idempotency key is derived from the trip ("trip:{id}:capture"), so a retry of the same step always reuses its key.
 */
final class TripCardPayments
{
    public function __construct(private readonly PaymentProcessor $payments, private readonly OutstandingBalance $outstanding) {}

    /**
     * Hold the money for a card trip that is being requested (trip creation, P7-T6, calls this).
     *
     * @throws PaymentException outstanding_balance, no_payment_method, card_declined
     */
    public function hold(Trip $trip): Transaction
    {
        $trip->payment_method === TripPaymentMethod::Card || throw new InvalidArgumentException('Only card trips hold money.');
        $this->outstanding->assertClear($trip->rider);
        $card = PaymentMethod::query()->whereKey($trip->payment_method_id)->where('user_id', $trip->rider_id)->first() ?? throw PaymentException::noCard();

        $percent = 100 + max(0, (int) config('payments.hold_buffer_percent'));
        $amount = max(1, intdiv($trip->estimated_fare_amount * $percent + 99, 100));
        $hold = $this->payments->authorize($trip->rider, $card, $amount, $trip->fare_currency, "trip:{$trip->id}:hold", $trip, ['purpose' => 'trip_hold']);

        if ($hold->status === TransactionStatus::Succeeded) {
            $trip->forceFill(['payment_status' => TripPaymentStatus::Authorized])->save();
        } elseif ($hold->status === TransactionStatus::Failed) {
            throw PaymentException::declined();
        }

        return $hold;
    }

    /** Take the final fare of a completed card trip. Safe to call again: each step reuses its key. */
    public function complete(Trip $trip): void
    {
        $this->assertCardTrip($trip, TripStatus::Completed);
        $this->settle($trip, (int) $trip->fare_amount);
    }

    /** A cancelled card trip: capture the cancellation fee (fare_amount) if there is one, otherwise release the hold. */
    public function cancel(Trip $trip): void
    {
        $this->assertCardTrip($trip, TripStatus::Cancelled);
        $fee = (int) $trip->fare_amount;

        if ($fee > 0) {
            $this->settle($trip, $fee);

            return;
        }

        $hold = $this->currentHold($trip);
        $void = $hold === null ? null : $this->payments->void($hold, "trip:{$trip->id}:void");

        if ($void !== null && $void->status !== TransactionStatus::Succeeded) {
            // The hold lapses on its own at the gateway; nothing is owed.
            Log::warning('payments.void_failed', ['trip_id' => $trip->id, 'transaction_id' => $void->id, 'failure_code' => $void->failure_code]);
        }

        $trip->forceFill(['payment_status' => TripPaymentStatus::Unpaid])->save();
    }

    /**
     * Renew holds that would lapse at the gateway before their trip ends: a new hold for the same amount, then the old one is
     * released. A renewal that fails keeps the old hold (the final capture may then fail and become an outstanding balance).
     *
     * @return int how many holds were renewed
     */
    public function renewExpiringHolds(): int
    {
        $renewed = 0;

        foreach ([PaymentGateway::Stripe, PaymentGateway::AuthorizeNet] as $gateway) {
            $hours = (int) config("payments.hold_lifetime_hours.{$gateway->value}") - (int) config('payments.hold_renew_margin_hours');

            Transaction::query()
                ->where('gateway', $gateway->value)
                ->where('type', TransactionType::Authorization->value)
                ->where('status', TransactionStatus::Succeeded->value)
                ->where('created_at', '<=', now()->subHours(max(0, $hours)))
                ->whereHas('trip', fn ($trips) => $trips->whereNotIn('status', [TripStatus::Completed->value, TripStatus::Cancelled->value, TripStatus::NoDriverFound->value]))
                ->whereDoesntHave('children', fn ($children) => $children->whereIn('type', [TransactionType::Capture->value, TransactionType::Void->value])
                    ->whereNotIn('status', [TransactionStatus::Failed->value, TransactionStatus::Cancelled->value]))
                ->with(['trip.rider', 'paymentMethod'])
                ->chunkById(100, function ($holds) use (&$renewed) {
                    foreach ($holds as $hold) {
                        $renewed += $this->renew($hold) ? 1 : 0;
                    }
                });
        }

        return $renewed;
    }

    /** The trip's hold that is still open: the newest succeeded authorization nothing has captured or voided. */
    public function currentHold(Trip $trip): ?Transaction
    {
        return Transaction::query()
            ->where('trip_id', $trip->id)
            ->where('type', TransactionType::Authorization->value)
            ->where('status', TransactionStatus::Succeeded->value)
            ->whereDoesntHave('children', fn ($children) => $children->whereIn('type', [TransactionType::Capture->value, TransactionType::Void->value])
                ->whereNotIn('status', [TransactionStatus::Failed->value, TransactionStatus::Cancelled->value]))
            ->latest('id')
            ->first();
    }

    /** Collect $amount for the trip: from the hold (and a charge for anything above it), or a charge when there is no hold. */
    private function settle(Trip $trip, int $amount): void
    {
        if ($trip->payment_status === TripPaymentStatus::Paid) {
            return;
        }

        $hold = $this->currentHold($trip) ?? $this->capturedHold($trip);
        $paid = $hold === null ? $this->charge($trip, $amount, "trip:{$trip->id}:charge") : $this->captureFrom($trip, $hold, $amount);

        $trip->forceFill(['payment_status' => $paid ? TripPaymentStatus::Paid : TripPaymentStatus::PaymentFailed])->save();

        if (! $paid) {
            Log::warning('payments.trip_payment_failed', ['trip_id' => $trip->id]);
        }
    }

    private function captureFrom(Trip $trip, Transaction $hold, int $amount): bool
    {
        $capture = $this->payments->capture($hold, min($amount, $hold->amount), "trip:{$trip->id}:capture");

        if ($capture->status !== TransactionStatus::Succeeded) {
            return false;
        }

        return $amount <= $hold->amount || $this->charge($trip, $amount - $hold->amount, "trip:{$trip->id}:extra");
    }

    private function charge(Trip $trip, int $amount, string $key): bool
    {
        $card = PaymentMethod::withTrashed()->whereKey($trip->payment_method_id)->where('user_id', $trip->rider_id)->first();

        return $card !== null
            && $this->payments->charge($trip->rider, $card, $amount, $trip->fare_currency, $key, $trip, ['purpose' => 'trip_fare'])->status === TransactionStatus::Succeeded;
    }

    /** On a retry after the capture went through, the hold is no longer "open": find it through its capture to finish the step. */
    private function capturedHold(Trip $trip): ?Transaction
    {
        return Transaction::query()->where('trip_id', $trip->id)->where('idempotency_key', "trip:{$trip->id}:capture")->first()?->parent;
    }

    private function renew(Transaction $hold): bool
    {
        $trip = $hold->trip;

        try {
            $card = $hold->paymentMethod ?? throw PaymentException::noCard();
            $new = $this->payments->authorize($trip->rider, $card, $hold->amount, $hold->currency, "trip:{$trip->id}:hold:renew:{$hold->id}", $trip, ['purpose' => 'trip_hold', 'renews' => $hold->id]);

            if ($new->status !== TransactionStatus::Succeeded) {
                Log::warning('payments.hold_renewal_failed', ['trip_id' => $trip->id, 'transaction_id' => $hold->id, 'failure_code' => $new->failure_code]);

                return false;
            }

            $this->payments->void($hold, "trip:{$trip->id}:void:{$hold->id}");

            return true;
        } catch (PaymentException|PaymentGatewayException $e) {
            Log::warning('payments.hold_renewal_failed', ['trip_id' => $trip->id, 'transaction_id' => $hold->id, 'error' => $e::class]);

            return false;
        }
    }

    private function assertCardTrip(Trip $trip, TripStatus $status): void
    {
        $trip->payment_method === TripPaymentMethod::Card || throw new InvalidArgumentException('Only card trips are paid through the gateway.');
        $trip->status === $status || throw new InvalidArgumentException("The trip must be {$status->value}.");
    }
}
