<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Data\PaymentResult;
use App\Enums\AppClient;
use App\Enums\PaymentGateway;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\Payments\Gateways\FakePaymentGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * P6-T2 · the gateway boundary: the interface takes the idempotency key as a required argument on every money call, every
 * gateway returns PaymentResult, and nothing outside App\Services\Payments talks to a gateway (or a gateway SDK).
 */
class PaymentGatewayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_money_method_takes_a_required_string_idempotency_key(): void
    {
        foreach (['authorize', 'capture', 'void', 'charge', 'refund'] as $method) {
            $parameter = collect((new ReflectionMethod(PaymentGatewayInterface::class, $method))->getParameters())->firstWhere('name', 'idempotencyKey');

            $this->assertNotNull($parameter, "{$method}() has no idempotencyKey parameter.");
            $this->assertFalse($parameter->isOptional(), "{$method}()'s idempotencyKey is optional.");
            $this->assertInstanceOf(ReflectionNamedType::class, $parameter->getType());
            $this->assertSame('string', $parameter->getType()->getName());
            $this->assertFalse($parameter->allowsNull(), "{$method}()'s idempotencyKey accepts null.");
        }
    }

    public function test_the_interface_has_the_full_method_set_returning_payment_results(): void
    {
        $returns = fn (string $method) => (string) (new ReflectionMethod(PaymentGatewayInterface::class, $method))->getReturnType();

        foreach (['authorize', 'capture', 'void', 'charge', 'refund', 'getStatus'] as $method) {
            $this->assertSame(PaymentResult::class, $returns($method));
        }
        $this->assertSame('string', $returns('createCustomer'));
        $this->assertSame('?App\Data\GatewayCard', $returns('attachPaymentMethod'));
        $this->assertSame('void', $returns('detachPaymentMethod'));
        $this->assertSame(['amount', 'currency', 'paymentMethod', 'idempotencyKey', 'meta'], array_column((new ReflectionMethod(PaymentGatewayInterface::class, 'charge'))->getParameters(), 'name'));
        $this->assertSame(['transactionId', 'amount', 'idempotencyKey'], array_column((new ReflectionMethod(PaymentGatewayInterface::class, 'refund'))->getParameters(), 'name'));
    }

    public function test_no_controller_model_or_trip_code_reaches_a_gateway_or_its_sdk(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $path = str_replace(app_path().'/', '', $file->getRealPath());
            $code = $file->getContents();

            // Gateway SDKs live only inside the gateway classes.
            if (! str_starts_with($path, 'Services/Payments/Gateways/') && preg_match('/\b(Stripe\\\\|StripeClient|net\\\\authorize|AnetAPI|AnetController)/', $code)) {
                $offenders[] = "{$path}: gateway SDK";
            }

            // The interface and the registry are used only by the payment services (and bound in the provider).
            $allowed = str_starts_with($path, 'Services/Payments/') || in_array($path, ['Contracts/PaymentGatewayInterface.php', 'Providers/AppServiceProvider.php'], true);

            if (! $allowed && preg_match('/\b(PaymentGatewayInterface|PaymentGateways|FakePaymentGateway)\b/', $code)) {
                $offenders[] = "{$path}: talks to a gateway directly";
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_fake_gateway_stands_in_for_each_card_gateway_only_outside_production(): void
    {
        config(['payments.driver' => 'fake']);
        $stripe = (new PaymentGateways(app(Repository::class), 'testing'))->for(PaymentGateway::Stripe);
        $this->assertInstanceOf(FakePaymentGateway::class, $stripe);
        $this->assertSame(PaymentGateway::Stripe, $stripe->name());
        $this->assertSame(168, $stripe->holdLifetimeHours());
        $this->assertSame(720, (new PaymentGateways(app(Repository::class), 'testing'))->for(PaymentGateway::AuthorizeNet)->holdLifetimeHours());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYMENT_DRIVER=fake is only for local, testing and e2e environments.');
        (new PaymentGateways(app(Repository::class), 'production'))->for(PaymentGateway::Stripe);
    }

    public function test_manual_and_wallet_are_not_card_gateways(): void
    {
        $this->expectException(RuntimeException::class);
        app(PaymentGateways::class)->for(PaymentGateway::Manual);
    }

    public function test_card_writes_share_the_payment_rate_limit(): void
    {
        config(['security.rate_limits.payment' => 3, 'payments.driver' => 'fake']);
        $rider = User::factory()->rider()->withPhone('+37491210447')->create();
        $headers = ['Authorization' => 'Bearer '.app(MobileSessionService::class)->issue($rider, AppClient::Rider, 'phone-1')->accessToken];

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/payment-methods', ['token' => 'tok_invalid'], $headers)->assertUnprocessable();
        }

        $this->postJson('/api/v1/account/outstanding/pay', [], $headers + ['Idempotency-Key' => 'pay-outstanding-0001'])->assertTooManyRequests()->assertHeader('Retry-After');
        // Reading is not limited by it.
        $this->getJson('/api/v1/payment-methods', $headers)->assertOk();
    }
}
