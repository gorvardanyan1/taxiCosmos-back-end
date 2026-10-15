<?php

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->rider(),
            'gateway' => PaymentGateway::Stripe,
            'gateway_reference' => 'pi_'.Str::random(24),
            'idempotency_key' => (string) Str::uuid(),
            'type' => TransactionType::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount' => 150000,
            'currency' => 'AMD',
        ];
    }

    public function status(TransactionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function failed(string $code = 'card_declined', string $message = 'Your card was declined.'): static
    {
        return $this->state(fn () => ['status' => TransactionStatus::Failed, 'failure_code' => $code, 'failure_message' => $message]);
    }

    public function gateway(PaymentGateway $gateway): static
    {
        return $this->state(fn () => ['gateway' => $gateway]);
    }

    /** A capture, void or refund of an earlier transaction (same rider and currency unless overridden). */
    public function of(Transaction $parent, TransactionType $type = TransactionType::Refund, ?int $amount = null): static
    {
        return $this->state(fn () => [
            'parent_transaction_id' => $parent->id, 'type' => $type, 'user_id' => $parent->user_id, 'trip_id' => $parent->trip_id, 'gateway' => $parent->gateway,
            'currency' => $parent->currency, 'amount' => $amount ?? $parent->amount,
        ]);
    }

    /** Recorded by an admin by hand. */
    public function manual(?User $admin = null): static
    {
        return $this->state(fn () => ['gateway' => PaymentGateway::Manual, 'gateway_reference' => null, 'created_by' => $admin?->id ?? User::factory()->create()->id]);
    }
}
