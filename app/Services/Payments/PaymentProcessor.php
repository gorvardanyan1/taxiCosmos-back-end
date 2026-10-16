<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Data\PaymentResult;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\PaymentException;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one place that moves card money (P6-T2): every hold, capture, void, charge and refund is a transactions row, and the only
 * caller of PaymentGatewayInterface's money methods.
 *
 * Each attempt is keyed by the caller's idempotency key, generated once per logical attempt. The row is written (status
 * `processing`, with the key) and committed before the gateway is called, so:
 *  - the same key again returns the original row and calls nothing (a finished attempt), or finishes it with the same key
 *    (an attempt whose answer was lost in a timeout: the gateway dedupes it, so the money still moves once);
 *  - the same key for a different payment is refused (409 idempotency_key_reused);
 *  - two attempts with one key at the same moment run one after the other (a lock per key), the second sees the first's row.
 * Callers must not wrap these calls in their own database transaction: the row has to be committed before the network call.
 */
final class PaymentProcessor
{
    /** Rows that still hold (or may still take) money on their parent: everything but a failed or cancelled attempt. */
    private const LIVE = [TransactionStatus::Pending, TransactionStatus::RequiresAction, TransactionStatus::Processing, TransactionStatus::Succeeded];

    public function __construct(private readonly PaymentGateways $gateways) {}

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function authorize(User $user, PaymentMethod $card, int $amount, string $currency, string $idempotencyKey, ?Trip $trip = null, array $meta = []): Transaction
    {
        $gateway = $this->gateways->for($card->gateway);

        return $this->attempt($gateway, $idempotencyKey, $this->newRow(TransactionType::Authorization, $user, $card, $amount, $currency, $trip), $meta, null,
            fn () => $gateway->authorize($amount, $currency, $card, $idempotencyKey, $this->gatewayMeta($user, $trip, $meta)));
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function charge(User $user, PaymentMethod $card, int $amount, string $currency, string $idempotencyKey, ?Trip $trip = null, array $meta = []): Transaction
    {
        $gateway = $this->gateways->for($card->gateway);

        return $this->attempt($gateway, $idempotencyKey, $this->newRow(TransactionType::Charge, $user, $card, $amount, $currency, $trip), $meta, null,
            fn () => $gateway->charge($amount, $currency, $card, $idempotencyKey, $this->gatewayMeta($user, $trip, $meta)));
    }

    /** Take up to the held amount. A hold is captured or voided once; the hold row is locked while that is decided. */
    public function capture(Transaction $hold, int $amount, string $idempotencyKey): Transaction
    {
        $gateway = $this->gateways->for($hold->gateway);

        return $this->attempt($gateway, $idempotencyKey, $this->childRow(TransactionType::Capture, $hold, $amount), [],
            function () use ($hold, $amount) {
                $locked = $this->openHold($hold);
                $amount <= $locked->amount || throw new InvalidArgumentException('A capture cannot be more than its hold.');
            },
            fn () => $gateway->capture((string) $hold->gateway_reference, $amount, $idempotencyKey));
    }

    /** Release a hold. */
    public function void(Transaction $hold, string $idempotencyKey): Transaction
    {
        $gateway = $this->gateways->for($hold->gateway);

        return $this->attempt($gateway, $idempotencyKey, $this->childRow(TransactionType::Void, $hold, $hold->amount), [],
            fn () => $this->openHold($hold),
            fn () => $gateway->void((string) $hold->gateway_reference, $idempotencyKey));
    }

    /** Give back part or all of a succeeded capture or charge; never more than is left to refund (checked under the row lock). */
    public function refund(Transaction $paid, int $amount, string $idempotencyKey): Transaction
    {
        $gateway = $this->gateways->for($paid->gateway);

        return $this->attempt($gateway, $idempotencyKey, $this->childRow(TransactionType::Refund, $paid, $amount), [],
            function () use ($paid, $amount) {
                $locked = Transaction::query()->lockForUpdate()->findOrFail($paid->getKey());

                if (! in_array($locked->type, [TransactionType::Capture, TransactionType::Charge], true) || $locked->status !== TransactionStatus::Succeeded) {
                    throw PaymentException::notRefundable();
                }

                $refunded = (int) $locked->children()->where('type', TransactionType::Refund->value)->whereIn('status', array_map(fn ($s) => $s->value, self::LIVE))->sum('amount');
                $amount <= $locked->amount - $refunded || throw PaymentException::refundExceedsRemaining();
            },
            fn () => $gateway->refund((string) $paid->gateway_reference, $amount, $idempotencyKey));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, scalar|null>  $meta
     * @param  (Closure(): mixed)|null  $precheck  runs in the transaction that inserts a new attempt (locks the parent row)
     * @param  Closure(): PaymentResult  $call
     */
    private function attempt(PaymentGatewayInterface $gateway, string $key, array $row, array $meta, ?Closure $precheck, Closure $call): Transaction
    {
        ($key !== '' && strlen($key) <= 100) || throw new InvalidArgumentException('An idempotency key of 1 to 100 characters is required.');
        $lock = Cache::lock('payments:attempt:'.$gateway->name()->value.':'.hash('sha256', $key), (int) config('payments.lock_seconds'));

        try {
            return $lock->block((int) config('payments.lock_wait_seconds'), fn () => $this->run($gateway, $key, $row, $meta, $precheck, $call));
        } catch (LockTimeoutException) {
            throw PaymentException::inProgress();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, scalar|null>  $meta
     * @param  (Closure(): mixed)|null  $precheck
     * @param  Closure(): PaymentResult  $call
     */
    private function run(PaymentGatewayInterface $gateway, string $key, array $row, array $meta, ?Closure $precheck, Closure $call): Transaction
    {
        $transaction = Transaction::query()->where('gateway', $gateway->name()->value)->where('idempotency_key', $key)->first();

        if ($transaction !== null) {
            $this->assertSameRequest($transaction, $row);

            if (! in_array($transaction->status, [TransactionStatus::Pending, TransactionStatus::Processing], true)) {
                return $transaction;
            }
        } else {
            try {
                $transaction = DB::transaction(function () use ($gateway, $key, $row, $meta, $precheck) {
                    $precheck?->__invoke();
                    $transaction = new Transaction;
                    $transaction->forceFill($row + [
                        'gateway' => $gateway->name(),
                        'idempotency_key' => $key,
                        'status' => TransactionStatus::Processing,
                        'metadata' => $meta === [] ? null : $meta,
                    ])->save();

                    return $transaction;
                });
            } catch (UniqueConstraintViolationException) {
                // Another server took this key between our read and our insert.
                throw PaymentException::inProgress();
            }
        }

        // A gateway exception leaves the row `processing`: the outcome is unknown, and a retry with this key finishes it.
        $result = $call();

        $transaction->forceFill([
            'status' => $result->status,
            'gateway_reference' => $result->gatewayReference ?? $transaction->gateway_reference,
            'failure_code' => $result->failureCode === null ? null : Str::limit($result->failureCode, 64, ''),
            'failure_message' => $result->failureMessage === null ? null : Str::limit($result->failureMessage, 500, ''),
            'metadata' => [...($transaction->metadata ?? []), 'gateway_response' => $result->rawResponse],
        ])->save();

        return $transaction;
    }

    /** The hold, locked, if it is a succeeded authorization nothing has captured or voided yet. */
    private function openHold(Transaction $hold): Transaction
    {
        $locked = Transaction::query()->lockForUpdate()->findOrFail($hold->getKey());

        $open = $locked->type === TransactionType::Authorization && $locked->status === TransactionStatus::Succeeded
            && ! $locked->children()->whereIn('type', [TransactionType::Capture->value, TransactionType::Void->value])
                ->whereIn('status', array_map(fn ($s) => $s->value, self::LIVE))->exists();

        return $open ? $locked : throw PaymentException::holdNotOpen();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertSameRequest(Transaction $existing, array $row): void
    {
        foreach ($row as $column => $value) {
            $stored = $existing->getAttribute($column);
            $same = $value instanceof \BackedEnum ? $stored === $value : (string) $stored === (string) $value;
            $same || throw PaymentException::idempotencyKeyReused();
        }
    }

    /** @return array<string, mixed> */
    private function newRow(TransactionType $type, User $user, PaymentMethod $card, int $amount, string $currency, ?Trip $trip): array
    {
        $card->user_id === $user->id || throw new InvalidArgumentException('The card does not belong to this rider.');
        $amount > 0 || throw new InvalidArgumentException('A payment amount must be positive.');

        return [
            'type' => $type, 'user_id' => $user->id, 'payment_method_id' => $card->id, 'trip_id' => $trip?->id,
            'parent_transaction_id' => null, 'amount' => $amount, 'currency' => $currency,
        ];
    }

    /** @return array<string, mixed> */
    private function childRow(TransactionType $type, Transaction $parent, int $amount): array
    {
        $amount > 0 || throw new InvalidArgumentException('A payment amount must be positive.');

        return [
            'type' => $type, 'user_id' => $parent->user_id, 'payment_method_id' => $parent->payment_method_id, 'trip_id' => $parent->trip_id,
            'parent_transaction_id' => $parent->id, 'amount' => $amount, 'currency' => $parent->currency,
        ];
    }

    /**
     * Ids that let staff find the payment in the gateway's dashboard. Never card data, names or phone numbers.
     *
     * @param  array<string, scalar|null>  $meta
     * @return array<string, scalar|null>
     */
    private function gatewayMeta(User $user, ?Trip $trip, array $meta): array
    {
        return ['user_id' => $user->id, 'trip_id' => $trip?->id, ...$meta];
    }
}
