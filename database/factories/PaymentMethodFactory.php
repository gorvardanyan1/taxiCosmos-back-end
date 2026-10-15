<?php

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->rider(),
            'gateway' => PaymentGateway::Stripe,
            'gateway_customer_id' => 'cus_'.Str::random(14),
            'gateway_payment_method_id' => 'pm_'.Str::random(24),
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 4,
            'exp_year' => (int) date('Y') + 3,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
