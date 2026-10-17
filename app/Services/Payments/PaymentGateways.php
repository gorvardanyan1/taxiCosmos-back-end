<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Services\Payments\Gateways\FakePaymentGateway;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;

/**
 * Picks the gateway class for a gateway name (P6-T2). A hold, capture or refund always goes back to the gateway that made the
 * original; a new card is saved with the configured one (payments.gateway). PAYMENT_DRIVER=fake answers every name with the
 * no-network fake, and only in local, testing and e2e.
 */
final class PaymentGateways
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $resolved = [];

    public function __construct(private readonly Cache $cache, private readonly string $environment) {}

    public function for(PaymentGateway $name): PaymentGatewayInterface
    {
        return $this->resolved[$name->value] ??= $this->make($name);
    }

    /** The gateway new cards are saved with. */
    public function default(): PaymentGatewayInterface
    {
        $name = PaymentGateway::tryFrom((string) config('payments.gateway'));

        return in_array($name, [PaymentGateway::Stripe, PaymentGateway::AuthorizeNet], true)
            ? $this->for($name)
            : throw new RuntimeException('PAYMENT_GATEWAY must be stripe or authorize_net (got "'.config('payments.gateway').'").');
    }

    private function make(PaymentGateway $name): PaymentGatewayInterface
    {
        if (! in_array($name, [PaymentGateway::Stripe, PaymentGateway::AuthorizeNet], true)) {
            throw new RuntimeException("{$name->value} is not a card gateway.");
        }

        return match (config('payments.driver')) {
            'fake' => in_array($this->environment, ['local', 'testing', 'e2e'], true)
                ? new FakePaymentGateway($name, $this->cache, (int) config("payments.hold_lifetime_hours.{$name->value}"))
                : throw new RuntimeException('PAYMENT_DRIVER=fake is only for local, testing and e2e environments.'),
            // The real gateway classes arrive with P6-T3 (Stripe) and P6-T4 (Authorize.Net). Until then card payments answer 503.
            'live' => throw PaymentGatewayException::notConfigured("The {$name->value} gateway is not installed yet."),
            default => throw new RuntimeException('PAYMENT_DRIVER must be fake or live (got "'.config('payments.driver').'").'),
        };
    }
}
