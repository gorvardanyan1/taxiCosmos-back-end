<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Data\GatewayCard;
use App\Data\PaymentResult;
use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * A card gateway with no network, standing in for Stripe or Authorize.Net in local, testing and e2e (PAYMENT_DRIVER=fake). It
 * behaves like the real ones where it matters: a repeated idempotency key returns the first answer and moves no money again,
 * holds lapse after the gateway's hold lifetime, a hold is captured or voided once, refunds cannot pass the amount taken.
 * Its state lives in the cache, so it holds across HTTP requests (Cypress).
 *
 * Test cards (the token the app would get from the gateway's own card form; docs/payments.md):
 * tok_visa (Visa 4242), tok_mastercard (Mastercard 4444), tok_declined (attaches, then every hold or charge is declined),
 * tok_capture_fails (holds work, the capture is declined), tok_invalid (refused at setup).
 */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    public const CARDS = [
        'tok_visa' => ['kind' => 'ok', 'brand' => 'visa', 'last4' => '4242'],
        'tok_mastercard' => ['kind' => 'ok', 'brand' => 'mastercard', 'last4' => '4444'],
        'tok_declined' => ['kind' => 'declined', 'brand' => 'visa', 'last4' => '0002'],
        'tok_capture_fails' => ['kind' => 'capturefails', 'brand' => 'visa', 'last4' => '0341'],
    ];

    private const TTL_SECONDS = 30 * 86400;

    /**
     * Every call this process made, for tests: [op, key, amount, moved] (moved=false when a repeated key was answered from memory).
     *
     * @var list<array{op: string, key: ?string, amount: ?int, moved: bool}>
     */
    public array $calls = [];

    /** The next money call throws as a timeout; with $applied the gateway did the work before the answer was lost. */
    private ?bool $timeoutNext = null;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Cache $cache,
        private readonly int $holdLifetimeHours,
    ) {}

    public function name(): PaymentGateway
    {
        return $this->gateway;
    }

    public function holdLifetimeHours(): int
    {
        return $this->holdLifetimeHours;
    }

    /** Make the next money call time out (tests). */
    public function timeoutNext(bool $applied = false): void
    {
        $this->timeoutNext = $applied;
    }

    /** @return int How many calls really moved money (repeats answered from the idempotency memory not counted). */
    public function moneyMovements(): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call['moved'] && $call['op'] !== 'getStatus'));
    }

    public function authorize(int $amount, string $currency, PaymentMethod $paymentMethod, string $idempotencyKey, array $meta = []): PaymentResult
    {
        return $this->once('authorize', $idempotencyKey, $amount, function () use ($amount, $currency, $paymentMethod) {
            if ($this->kind($paymentMethod) === 'declined') {
                return PaymentResult::failed(null, 'card_declined', 'Your card was declined.');
            }

            return $this->store('authorization', $amount, $currency, $paymentMethod);
        });
    }

    public function capture(string $authorizationId, int $amount, string $idempotencyKey): PaymentResult
    {
        return $this->once('capture', $idempotencyKey, $amount, function () use ($authorizationId, $amount) {
            $hold = $this->find($authorizationId);

            return match (true) {
                $hold === null || $hold['type'] !== 'authorization' => PaymentResult::failed(null, 'resource_missing', 'No such hold.'),
                $hold['voided'] || $hold['captured'] > 0 => PaymentResult::failed(null, 'hold_closed', 'This hold was already captured or released.'),
                now()->getTimestamp() > $hold['created_at'] + $this->holdLifetimeHours * 3600 => PaymentResult::failed(null, 'authorization_expired', 'The hold has expired.'),
                $amount > $hold['amount'] => PaymentResult::failed(null, 'amount_too_large', 'The capture is more than the hold.'),
                $hold['kind'] === 'capturefails' => PaymentResult::failed(null, 'card_declined', 'Your card was declined.'),
                default => $this->captureHold($authorizationId, $hold, $amount),
            };
        });
    }

    public function void(string $authorizationId, string $idempotencyKey): PaymentResult
    {
        return $this->once('void', $idempotencyKey, null, function () use ($authorizationId) {
            $hold = $this->find($authorizationId);

            if ($hold === null || $hold['type'] !== 'authorization' || $hold['captured'] > 0) {
                return PaymentResult::failed(null, 'hold_closed', 'This hold cannot be released.');
            }

            $this->put($authorizationId, ['voided' => true, 'status' => 'cancelled'] + $hold);

            return PaymentResult::succeeded('fake_void_'.Str::lower(Str::random(16)), ['object' => 'void', 'hold' => $authorizationId]);
        });
    }

    public function charge(int $amount, string $currency, PaymentMethod $paymentMethod, string $idempotencyKey, array $meta = []): PaymentResult
    {
        return $this->once('charge', $idempotencyKey, $amount, function () use ($amount, $currency, $paymentMethod) {
            if ($this->kind($paymentMethod) === 'declined') {
                return PaymentResult::failed(null, 'card_declined', 'Your card was declined.');
            }

            return $this->store('charge', $amount, $currency, $paymentMethod);
        });
    }

    public function refund(string $transactionId, int $amount, string $idempotencyKey): PaymentResult
    {
        return $this->once('refund', $idempotencyKey, $amount, function () use ($transactionId, $amount) {
            $paid = $this->find($transactionId);

            if ($paid === null || ! in_array($paid['type'], ['capture', 'charge'], true) || $amount > $paid['amount'] - $paid['refunded']) {
                return PaymentResult::failed(null, 'refund_invalid', 'This amount cannot be refunded.');
            }

            $this->put($transactionId, ['refunded' => $paid['refunded'] + $amount] + $paid);

            return PaymentResult::succeeded('fake_re_'.Str::lower(Str::random(16)), ['object' => 'refund', 'amount' => $amount]);
        });
    }

    public function getStatus(string $transactionId): PaymentResult
    {
        $this->calls[] = ['op' => 'getStatus', 'key' => null, 'amount' => null, 'moved' => false];
        $found = $this->find($transactionId);

        return $found === null
            ? PaymentResult::failed($transactionId, 'resource_missing', 'No such transaction.')
            : new PaymentResult(TransactionStatus::from($found['status']), $transactionId, ['object' => $found['type'], 'amount' => $found['amount']]);
    }

    public function createCustomer(User $user): string
    {
        $this->calls[] = ['op' => 'createCustomer', 'key' => null, 'amount' => null, 'moved' => false];

        return 'fake_cus_'.Str::lower(Str::random(16));
    }

    public function attachPaymentMethod(string $customerId, string $token): ?GatewayCard
    {
        $this->calls[] = ['op' => 'attachPaymentMethod', 'key' => null, 'amount' => null, 'moved' => false];
        $card = self::CARDS[$token] ?? null;

        if ($card === null) {
            return null;
        }

        return new GatewayCard('fake_pm_'.$card['kind'].'_'.Str::lower(Str::random(16)), $card['brand'], $card['last4'], 12, (int) now()->format('Y') + 3);
    }

    public function detachPaymentMethod(PaymentMethod $paymentMethod): void
    {
        $this->calls[] = ['op' => 'detachPaymentMethod', 'key' => null, 'amount' => null, 'moved' => false];
    }

    /**
     * Answer a money call once per idempotency key, like Stripe: a repeat gets the first answer and moves nothing.
     *
     * @param  callable(): PaymentResult  $work
     */
    private function once(string $op, string $key, ?int $amount, callable $work): PaymentResult
    {
        $memoryKey = $this->prefix().'key:'.hash('sha256', $key);
        $remembered = $this->cache->get($memoryKey);

        if ($remembered === null && $this->timeoutNext === false) {
            $this->timeoutNext = null;
            $this->calls[] = ['op' => $op, 'key' => $key, 'amount' => $amount, 'moved' => false];

            throw PaymentGatewayException::unreachable('Fake gateway timeout.');
        }

        if ($remembered !== null) {
            $this->calls[] = ['op' => $op, 'key' => $key, 'amount' => $amount, 'moved' => false];

            return $remembered['op'] === $op
                ? new PaymentResult(TransactionStatus::from($remembered['status']), $remembered['reference'], $remembered['raw'], $remembered['failure_code'], $remembered['failure_message'])
                : PaymentResult::failed(null, 'idempotency_error', 'Keys for idempotent requests can only be used with the same parameters.');
        }

        $result = $work();
        $this->calls[] = ['op' => $op, 'key' => $key, 'amount' => $amount, 'moved' => true];
        $this->cache->put($memoryKey, [
            'op' => $op, 'status' => $result->status->value, 'reference' => $result->gatewayReference, 'raw' => $result->rawResponse,
            'failure_code' => $result->failureCode, 'failure_message' => $result->failureMessage,
        ], self::TTL_SECONDS);

        if ($this->timeoutNext === true) {
            $this->timeoutNext = null;

            throw PaymentGatewayException::unreachable('Fake gateway timeout after the work was done.');
        }

        return $result;
    }

    private function store(string $type, int $amount, string $currency, PaymentMethod $paymentMethod): PaymentResult
    {
        $reference = ($type === 'authorization' ? 'fake_auth_' : 'fake_ch_').Str::lower(Str::random(16));
        $this->put($reference, [
            'type' => $type, 'amount' => $amount, 'currency' => $currency, 'kind' => $this->kind($paymentMethod), 'status' => 'succeeded',
            'captured' => 0, 'voided' => false, 'refunded' => 0, 'created_at' => now()->getTimestamp(),
        ]);

        return PaymentResult::succeeded($reference, ['object' => $type, 'amount' => $amount, 'currency' => $currency]);
    }

    /**
     * @param  array<string, mixed>  $hold
     */
    private function captureHold(string $authorizationId, array $hold, int $amount): PaymentResult
    {
        $this->put($authorizationId, ['captured' => $amount] + $hold);
        $reference = 'fake_cap_'.Str::lower(Str::random(16));
        $this->put($reference, ['type' => 'capture', 'amount' => $amount, 'status' => 'succeeded', 'refunded' => 0] + $hold);

        return PaymentResult::succeeded($reference, ['object' => 'capture', 'amount' => $amount, 'hold' => $authorizationId]);
    }

    private function kind(PaymentMethod $paymentMethod): string
    {
        return Str::between($paymentMethod->gateway_payment_method_id, 'fake_pm_', '_');
    }

    /** @return array<string, mixed>|null */
    private function find(string $reference): ?array
    {
        return $this->cache->get($this->prefix().'ref:'.$reference);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function put(string $reference, array $state): void
    {
        $this->cache->put($this->prefix().'ref:'.$reference, $state, self::TTL_SECONDS);
    }

    private function prefix(): string
    {
        return 'fake-payment-gateway:'.$this->gateway->value.':';
    }
}
