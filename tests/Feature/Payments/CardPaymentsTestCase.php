<?php

namespace Tests\Feature\Payments;

use App\Enums\AppClient;
use App\Enums\PaymentGateway;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Models\PaymentMethod;
use App\Models\Trip;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\Payments\Gateways\FakePaymentGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

/**
 * Shared steps for the P6-T2 tests: the real payment services against the fake card gateway (no network), with real rows.
 */
abstract class CardPaymentsTestCase extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
        config(['payments.driver' => 'fake', 'payments.gateway' => 'stripe']);
    }

    /** Each request authenticates from its own token (see AccountTestCase). */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function gateway(PaymentGateway $name = PaymentGateway::Stripe): FakePaymentGateway
    {
        return app(PaymentGateways::class)->for($name);
    }

    protected function rider(string $phone = '+37491210443'): User
    {
        return User::factory()->rider()->withPhone($phone)->create();
    }

    /** A saved card the fake gateway knows: kind ok, declined or capturefails (see FakePaymentGateway::CARDS). */
    protected function card(User $rider, string $kind = 'ok', array $attributes = []): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'user_id' => $rider->id,
            'gateway_customer_id' => 'fake_cus_'.Str::lower(Str::random(12)),
            'gateway_payment_method_id' => 'fake_pm_'.$kind.'_'.Str::lower(Str::random(12)),
            ...$attributes,
        ]);
    }

    /** A card trip (1 500.00 AMD estimate unless told otherwise) in the factory state given. */
    protected function cardTrip(User $rider, PaymentMethod $card, string $state = 'inProgress', array $attributes = []): Trip
    {
        $factory = Trip::factory();
        $factory = $state === 'requested' ? $factory : $factory->{$state}();

        return $factory->create([
            'rider_id' => $rider->id,
            'payment_method' => TripPaymentMethod::Card,
            'payment_method_id' => $card->id,
            'payment_status' => TripPaymentStatus::Unpaid,
            ...$attributes,
        ]);
    }

    protected function as(User $user, AppClient $app = AppClient::Rider): array
    {
        return $this->bearer(app(MobileSessionService::class)->issue($user, $app, 'phone-1')->accessToken);
    }
}
