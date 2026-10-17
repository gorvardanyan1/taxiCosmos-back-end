<?php

namespace App\Services\Payments;

use App\Enums\TripStatus;
use App\Exceptions\PaymentException;
use App\Models\PaymentMethod;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A rider's saved cards (P6-T2). The app tokenises the card in the gateway's own form; only the token comes here, and only the
 * gateway's ids plus brand, last four digits and expiry are kept. One live default card per rider: the first card becomes it,
 * and removing the default promotes the newest remaining card.
 */
final class SavedCards
{
    public function __construct(private readonly PaymentGateways $gateways) {}

    /** @return Collection<int, PaymentMethod> default first, then newest */
    public function list(User $rider): Collection
    {
        return PaymentMethod::query()->where('user_id', $rider->id)->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    public function default(User $rider): ?PaymentMethod
    {
        return PaymentMethod::query()->where('user_id', $rider->id)->where('is_default', true)->first();
    }

    /**
     * Save the card behind a gateway token on the rider's gateway customer (created on the first card).
     *
     * @return array{card: PaymentMethod, created: bool} created=false when this card was already saved
     */
    public function add(User $rider, string $token, bool $makeDefault): array
    {
        try {
            return Cache::lock('payments:cards:'.$rider->id, (int) config('payments.lock_seconds'))
                ->block((int) config('payments.lock_wait_seconds'), fn () => $this->addLocked($rider, $token, $makeDefault));
        } catch (LockTimeoutException) {
            throw PaymentException::inProgress();
        }
    }

    /** Remove a card at the gateway, then here (soft delete: past transactions keep pointing at it). */
    public function remove(User $rider, PaymentMethod $card): void
    {
        $inUse = Trip::query()->where('payment_method_id', $card->id)
            ->whereNotIn('status', [TripStatus::Completed->value, TripStatus::Cancelled->value, TripStatus::NoDriverFound->value])->exists();
        $inUse && throw PaymentException::cardInUse();

        $this->gateways->for($card->gateway)->detachPaymentMethod($card);

        DB::transaction(function () use ($rider, $card) {
            $this->lockCards($rider);
            $wasDefault = $card->is_default;
            $card->forceFill(['is_default' => false])->save();
            $card->delete();

            if ($wasDefault) {
                PaymentMethod::query()->where('user_id', $rider->id)->latest('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }

    public function makeDefault(User $rider, PaymentMethod $card): PaymentMethod
    {
        DB::transaction(function () use ($rider, $card) {
            $this->lockCards($rider);
            PaymentMethod::query()->where('user_id', $rider->id)->whereKeyNot($card->id)->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
            $card->forceFill(['is_default' => true])->save();
        });

        return $card->refresh();
    }

    /** @return array{card: PaymentMethod, created: bool} */
    private function addLocked(User $rider, string $token, bool $makeDefault): array
    {
        PaymentMethod::query()->where('user_id', $rider->id)->count() < (int) config('payments.max_cards') || throw PaymentException::cardLimit();

        $gateway = $this->gateways->default();
        $customerId = PaymentMethod::withTrashed()->where('user_id', $rider->id)->where('gateway', $gateway->name()->value)
            ->whereNotNull('gateway_customer_id')->latest('id')->value('gateway_customer_id') ?? $gateway->createCustomer($rider);

        $saved = $gateway->attachPaymentMethod($customerId, $token)
            ?? throw ValidationException::withMessages(['token' => __('This card could not be saved. Check it or try another card.')]);

        $existing = PaymentMethod::query()->where('user_id', $rider->id)->where('gateway', $gateway->name()->value)
            ->where('gateway_payment_method_id', $saved->gatewayPaymentMethodId)->first();

        if ($existing !== null) {
            return ['card' => $makeDefault ? $this->makeDefault($rider, $existing) : $existing, 'created' => false];
        }

        $card = DB::transaction(function () use ($rider, $gateway, $customerId, $saved, $makeDefault) {
            $this->lockCards($rider);
            $isDefault = $makeDefault || ! PaymentMethod::query()->where('user_id', $rider->id)->where('is_default', true)->exists();

            if ($isDefault) {
                PaymentMethod::query()->where('user_id', $rider->id)->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
            }

            $card = new PaymentMethod;
            $card->forceFill([
                'user_id' => $rider->id,
                'gateway' => $gateway->name(),
                'gateway_customer_id' => $customerId,
                'gateway_payment_method_id' => $saved->gatewayPaymentMethodId,
                'brand' => $saved->brand,
                'last4' => $saved->last4,
                'exp_month' => $saved->expMonth,
                'exp_year' => $saved->expYear,
                'is_default' => $isDefault,
            ])->save();

            return $card;
        });

        return ['card' => $card->refresh(), 'created' => true];
    }

    /** Serialises changes to one rider's default card (the partial unique index allows one live default). */
    private function lockCards(User $rider): void
    {
        User::query()->whereKey($rider->id)->lockForUpdate()->first();
    }
}
