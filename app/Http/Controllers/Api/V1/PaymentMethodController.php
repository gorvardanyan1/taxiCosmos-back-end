<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentMethodRequest;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\SavedCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The rider app's saved cards (P6-T2). Every card is looked up through the signed-in rider, so another rider's card is "not found".
 */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly SavedCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => PaymentMethodResource::collection($this->cards->list($this->rider($request)))->resolve()]);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        ['card' => $card, 'created' => $created] = $this->cards->add($this->rider($request), $request->validated('token'), $request->boolean('make_default'));

        return response()->json(['data' => (new PaymentMethodResource($card))->resolve()], $created ? 201 : 200);
    }

    public function destroy(Request $request, int $paymentMethod): Response
    {
        $this->cards->remove($this->rider($request), $this->mine($request, $paymentMethod));

        return response()->noContent();
    }

    public function makeDefault(Request $request, int $paymentMethod): JsonResponse
    {
        $card = $this->cards->makeDefault($this->rider($request), $this->mine($request, $paymentMethod));

        return response()->json(['data' => (new PaymentMethodResource($card))->resolve()]);
    }

    private function mine(Request $request, int $id): PaymentMethod
    {
        return PaymentMethod::query()->where('user_id', $this->rider($request)->id)->findOrFail($id);
    }

    private function rider(Request $request): User
    {
        return $request->user();
    }
}
