<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PayOutstandingRequest;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\OutstandingBalance;
use App\Services\Payments\SavedCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What the rider owes for card trips whose final payment failed, and paying it (P6-T2). While anything is owed the app asks the
 * rider to update the card and new card trips are refused.
 */
class OutstandingBalanceController extends Controller
{
    public function __construct(private readonly OutstandingBalance $outstanding, private readonly SavedCards $cards) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summary($this->rider($request))]);
    }

    public function pay(PayOutstandingRequest $request): JsonResponse
    {
        $rider = $this->rider($request);
        $this->outstanding->pay($rider, $this->card($rider, $request->validated('payment_method_id')), $request->validated('idempotency_key'));

        return response()->json(['data' => $this->summary($rider)]);
    }

    private function card(User $rider, ?int $id): PaymentMethod
    {
        if ($id === null) {
            return $this->cards->default($rider) ?? throw PaymentException::noCard();
        }

        return PaymentMethod::query()->where('user_id', $rider->id)->find($id)
            ?? throw ValidationException::withMessages(['payment_method_id' => __('Choose one of your saved cards.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $rider): array
    {
        $trips = $this->outstanding->trips($rider);

        return [
            'has_outstanding' => $trips->isNotEmpty(),
            'totals' => $this->outstanding->totals($rider),
            'trips' => $trips->map(fn ($trip) => [
                'id' => $trip->id,
                'public_code' => $trip->public_code,
                'amount' => (int) $trip->owed,
                'currency' => $trip->fare_currency,
                'ended_at' => ($trip->completed_at ?? $trip->cancelled_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function rider(Request $request): User
    {
        return $request->user();
    }
}
