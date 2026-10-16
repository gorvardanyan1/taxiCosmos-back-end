<?php

namespace App\Contracts;

use App\Data\GatewayCard;
use App\Data\PaymentResult;
use App\Enums\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentMethod;
use App\Models\User;

/**
 * A card gateway (P6-T2): Stripe (P6-T3), Authorize.Net (P6-T4), or the fake one in local, testing and e2e. Only
 * App\Services\Payments talks to it; controllers and trip logic never touch a gateway or its SDK.
 *
 * Amounts are integers in minor units. Every call that moves money takes the caller's idempotency key as a required
 * argument: the same key always means the same attempt, so a retry after a timeout cannot charge twice (Stripe gets it as
 * its Idempotency-Key header; a gateway without one dedupes on our transactions row). A declined card is not an exception:
 * it comes back as a failed PaymentResult. An exception means the outcome is unknown and the same key must be retried.
 */
interface PaymentGatewayInterface
{
    /** Which gateway this is (what the transactions and payment_methods rows record). */
    public function name(): PaymentGateway;

    /** Hours this gateway keeps an uncaptured hold before it lapses (Stripe: 7 days). */
    public function holdLifetimeHours(): int;

    /**
     * Hold the amount on the card without taking it.
     *
     * @param  array<string, scalar|null>  $meta  ids for the gateway dashboard (trip, rider); never card data or personal data
     *
     * @throws PaymentGatewayException
     */
    public function authorize(int $amount, string $currency, PaymentMethod $paymentMethod, string $idempotencyKey, array $meta = []): PaymentResult;

    /**
     * Take up to the held amount of an earlier authorize().
     *
     * @throws PaymentGatewayException
     */
    public function capture(string $authorizationId, int $amount, string $idempotencyKey): PaymentResult;

    /**
     * Release a hold without taking anything.
     *
     * @throws PaymentGatewayException
     */
    public function void(string $authorizationId, string $idempotencyKey): PaymentResult;

    /**
     * Take the amount at once.
     *
     * @param  array<string, scalar|null>  $meta
     *
     * @throws PaymentGatewayException
     */
    public function charge(int $amount, string $currency, PaymentMethod $paymentMethod, string $idempotencyKey, array $meta = []): PaymentResult;

    /**
     * Give back part or all of a capture or charge.
     *
     * @throws PaymentGatewayException
     */
    public function refund(string $transactionId, int $amount, string $idempotencyKey): PaymentResult;

    /**
     * What the gateway says about a transaction now (for reconciling a row whose webhook never came).
     *
     * @throws PaymentGatewayException
     */
    public function getStatus(string $transactionId): PaymentResult;

    /**
     * Create the gateway's customer record for a rider; returns the gateway's customer id.
     *
     * @throws PaymentGatewayException
     */
    public function createCustomer(User $user): string;

    /**
     * Save a card the app tokenised (Stripe PaymentSheet / Accept.js) on the customer. Card numbers never pass through here.
     * Returns null when the gateway refuses the token (unknown, used, or the card was declined at setup).
     *
     * @throws PaymentGatewayException
     */
    public function attachPaymentMethod(string $customerId, string $token): ?GatewayCard;

    /**
     * Remove a saved card from the gateway customer.
     *
     * @throws PaymentGatewayException
     */
    public function detachPaymentMethod(PaymentMethod $paymentMethod): void;
}
