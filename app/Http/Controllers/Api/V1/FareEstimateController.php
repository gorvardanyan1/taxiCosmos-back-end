<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FareEstimateRequest;
use App\Http\Resources\Api\V1\FareQuoteResource;
use App\Models\User;
use App\Services\Fares\FareEstimator;
use Illuminate\Http\JsonResponse;

class FareEstimateController extends Controller
{
    /**
     * Prices a trip for every vehicle class in the pickup zone and stores the quote. Errors carry a
     * stable `code`: outside_service_area, no_fare_rules.
     */
    public function __invoke(FareEstimateRequest $request, FareEstimator $estimator): JsonResponse
    {
        /** @var User $rider */
        $rider = $request->user();
        $quote = $estimator->quote($rider, $request->pickup(), $request->dropoff());

        return response()->json((new FareQuoteResource($quote->load('zone:id,name')))->resolve(), 201);
    }
}
