<?php

namespace Tests\Feature\Payments;

use App\Enums\AppClient;
use App\Enums\TripPaymentStatus;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\PaymentGateways;

/**
 * P6-T2 · GET/POST/DELETE /api/v1/payment-methods and POST /api/v1/payment-methods/{id}/default: the rider's saved cards, each
 * rider only their own, one default, only gateway tokens stored.
 */
class PaymentMethodApiTest extends CardPaymentsTestCase
{
    public function test_a_card_is_saved_from_a_gateway_token_and_becomes_the_first_default(): void
    {
        $rider = $this->rider();

        $response = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $this->as($rider))->assertCreated();

        $card = PaymentMethod::query()->sole();
        $response->assertExactJson(['data' => [
            'id' => $card->id, 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => (int) now()->format('Y') + 3,
            'is_default' => true, 'is_expired' => false,
        ]]);
        $this->assertSame($rider->id, $card->user_id);
        $this->assertStringStartsWith('fake_pm_ok_', $card->gateway_payment_method_id);
        $this->assertStringStartsWith('fake_cus_', $card->gateway_customer_id);
        $this->assertSame(['createCustomer', 'attachPaymentMethod'], array_column($this->gateway()->calls, 'op'));
    }

    public function test_a_second_card_reuses_the_customer_and_is_not_default_unless_asked(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        $first = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $headers)->json('data.id');

        $second = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_mastercard'], $headers)->assertCreated()
            ->assertJsonPath('data.is_default', false)->assertJsonPath('data.brand', 'mastercard')->json('data.id');
        $third = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_amex', 'make_default' => true], $headers)->assertCreated()
            ->assertJsonPath('data.is_default', true)->json('data.id');

        $this->assertSame(1, count(array_unique(PaymentMethod::query()->pluck('gateway_customer_id')->all())));
        $this->assertSame(1, count(array_filter(array_column($this->gateway()->calls, 'op'), fn ($op) => $op === 'createCustomer')));
        $this->assertSame([$third], PaymentMethod::query()->where('is_default', true)->pluck('id')->all());
        // Default first, then newest.
        $this->getJson('/api/v1/payment-methods', $headers)->assertOk()->assertJsonPath('data.*.id', [$third, $second, $first]);
    }

    public function test_adding_the_same_card_again_returns_it_instead_of_failing_or_duplicating(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);
        $visa = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $headers)->assertCreated()->json('data.id');
        $mastercard = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_mastercard'], $headers)->assertCreated()->json('data.id');

        // The app timed out and sends the same token again: the gateway hands back the same card.
        $again = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_mastercard'], $headers)->assertOk()
            ->assertJsonPath('data.id', $mastercard)->assertJsonPath('data.is_default', false);
        $this->assertSame(2, PaymentMethod::query()->count());

        // Asking for it to be the default on the retry still makes it so, and only it.
        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_mastercard', 'make_default' => true], $headers)->assertOk()
            ->assertJsonPath('data.id', $mastercard)->assertJsonPath('data.is_default', true);
        $this->assertSame([$mastercard], PaymentMethod::query()->where('is_default', true)->pluck('id')->all());
        $this->assertFalse(PaymentMethod::query()->findOrFail($visa)->is_default);
        $this->assertSame($again->json('data.last4'), '4444');
        // One customer for the rider, however many attempts.
        $this->assertSame(1, count(array_filter(array_column($this->gateway()->calls, 'op'), fn ($op) => $op === 'createCustomer')));
    }

    public function test_two_riders_adding_the_same_test_token_get_separate_cards(): void
    {
        $first = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $this->as($this->rider()))->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $this->as($this->rider('+37491210444')))->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, PaymentMethod::query()->distinct()->count('gateway_payment_method_id'));
    }

    public function test_the_list_shows_only_the_riders_own_live_cards_without_gateway_ids(): void
    {
        $rider = $this->rider();
        $mine = $this->card($rider, attributes: ['is_default' => true]);
        $removed = $this->card($rider);
        $removed->delete();
        $this->card($this->rider('+37491210444'));
        $expired = $this->card($rider, attributes: ['exp_month' => 1, 'exp_year' => (int) now()->format('Y') - 1]);

        $response = $this->getJson('/api/v1/payment-methods', $this->as($rider))->assertOk();

        $response->assertJsonPath('data.*.id', [$mine->id, $expired->id])->assertJsonPath('data.1.is_expired', true);
        $this->assertStringNotContainsString('fake_pm_', $response->getContent());
        $this->assertStringNotContainsString('fake_cus_', $response->getContent());
        $this->assertStringNotContainsString('gateway', $response->getContent());
    }

    public function test_a_refused_or_malformed_token_saves_nothing(): void
    {
        $rider = $this->rider();
        $headers = $this->as($rider);

        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_invalid'], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => 'This card could not be saved. Check it or try another card.']);
        $this->postJson('/api/v1/payment-methods', [], $headers)->assertUnprocessable()->assertJsonValidationErrors('token');
        // A card number is not a token.
        $this->postJson('/api/v1/payment-methods', ['token' => '4242424242424242'], $headers)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/payment-methods', ['token' => 'tok visa'], $headers)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa', 'make_default' => 'maybe'], $headers)->assertUnprocessable()->assertJsonValidationErrors('make_default');

        $this->assertSame(0, PaymentMethod::withTrashed()->count());
    }

    public function test_the_card_limit_is_enforced(): void
    {
        config(['payments.max_cards' => 2]);
        $rider = $this->rider();
        $this->card($rider);
        $this->card($rider);

        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $this->as($rider))->assertStatus(409)->assertJsonPath('code', 'card_limit_reached');

        $this->assertSame(2, PaymentMethod::query()->count());
        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_removing_the_default_card_detaches_it_and_promotes_the_newest(): void
    {
        $rider = $this->rider();
        $older = $this->card($rider);
        $newer = $this->card($rider);
        $default = $this->card($rider, attributes: ['is_default' => true]);

        $this->deleteJson("/api/v1/payment-methods/{$default->id}", [], $this->as($rider))->assertNoContent();

        $this->assertSoftDeleted($default);
        $this->assertFalse($default->fresh()->is_default);
        $this->assertTrue($newer->fresh()->is_default);
        $this->assertFalse($older->fresh()->is_default);
        $this->assertSame(['detachPaymentMethod'], array_column($this->gateway()->calls, 'op'));
    }

    public function test_a_card_paying_for_an_active_trip_cannot_be_removed(): void
    {
        $rider = $this->rider();
        $card = $this->card($rider);
        $this->cardTrip($rider, $card, 'inProgress', ['payment_status' => TripPaymentStatus::Authorized]);

        $this->deleteJson("/api/v1/payment-methods/{$card->id}", [], $this->as($rider))->assertStatus(409)->assertJsonPath('code', 'card_in_use');

        $this->assertNotSoftDeleted($card);
        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_setting_the_default_moves_it_from_the_other_card(): void
    {
        $rider = $this->rider();
        $old = $this->card($rider, attributes: ['is_default' => true]);
        $card = $this->card($rider);

        $this->postJson("/api/v1/payment-methods/{$card->id}/default", [], $this->as($rider))->assertOk()
            ->assertJsonPath('data.id', $card->id)->assertJsonPath('data.is_default', true);

        $this->assertTrue($card->fresh()->is_default);
        $this->assertFalse($old->fresh()->is_default);
        // Twice is harmless.
        $this->postJson("/api/v1/payment-methods/{$card->id}/default", [], $this->as($rider))->assertOk();
        $this->assertSame(1, PaymentMethod::query()->where('is_default', true)->count());
    }

    public function test_another_riders_card_is_not_found_and_left_untouched(): void
    {
        $rider = $this->rider();
        $theirs = $this->card($this->rider('+37491210444'), attributes: ['is_default' => true]);
        $headers = $this->as($rider);

        $this->deleteJson("/api/v1/payment-methods/{$theirs->id}", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/payment-methods/{$theirs->id}/default", [], $headers)->assertNotFound();
        $this->deleteJson('/api/v1/payment-methods/999999', [], $headers)->assertNotFound();

        $this->assertNotSoftDeleted($theirs);
        $this->assertTrue($theirs->fresh()->is_default);
        $this->assertSame([], $this->gateway()->calls);
    }

    public function test_guests_and_the_driver_app_are_refused(): void
    {
        $this->getJson('/api/v1/payment-methods')->assertUnauthorized();
        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'])->assertUnauthorized();

        $driver = User::factory()->driver()->withPhone('+37491210446')->create();
        $headers = $this->as($driver, AppClient::Driver);
        $this->getJson('/api/v1/payment-methods', $headers)->assertForbidden();
        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $headers)->assertForbidden();
        $this->getJson('/api/v1/account/outstanding', $headers)->assertForbidden();

        $this->assertSame(0, PaymentMethod::query()->count());
    }

    public function test_without_an_installed_gateway_card_saving_answers_503_and_saves_nothing(): void
    {
        config(['payments.driver' => 'live']);
        app()->forgetInstance(PaymentGateways::class);
        $rider = $this->rider();

        $this->postJson('/api/v1/payment-methods', ['token' => 'tok_visa'], $this->as($rider))->assertStatus(503)
            ->assertJsonPath('code', 'payment_gateway_unavailable');

        $this->assertSame(0, PaymentMethod::query()->count());
    }
}
