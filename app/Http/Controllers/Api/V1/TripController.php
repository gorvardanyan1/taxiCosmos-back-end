<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripStatus;
use App\Exceptions\TripStateException;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithTrips;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelTripRequest;
use App\Http\Requests\Api\V1\StoreTripRequest;
use App\Http\Resources\Api\V1\TripReceiptResource;
use App\Http\Resources\Api\V1\TripResource;
use App\Models\Trip;
use App\Models\User;
use App\Services\Trips\TripEta;
use App\Services\Trips\TripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The rider's side of a trip (P7-T6): request, cancel, the current trip, one trip, the history and a receipt. A trip is always looked up
 * through the signed-in rider, so another rider's trip is simply "not found". Booking and cancelling take an Idempotency-Key
 * (the `idempotent` middleware): a retry gets the first answer back and changes nothing.
 */
class TripController extends Controller
{
    use RespondsWithTrips;

    private const PER_PAGE = 20;

    public function __construct(private readonly TripService $trips, private readonly TripEta $eta) {}

    public function store(StoreTripRequest $request): JsonResponse
    {
        $trip = $this->trips->request(
            $this->rider($request), $request->validated('quote_id'), $request->vehicleClass(), $request->paymentMethod(),
            $request->pickup(), $request->validated('pickup.address'), $request->dropoff(), $request->validated('dropoff.address'),
        );

        return response()->json(['data' => $this->present($trip)], 201);
    }

    public function current(Request $request): JsonResponse|Response
    {
        $trip = TripService::activeFor($this->rider($request));

        return $trip === null ? response()->noContent() : response()->json(['data' => $this->present($trip)]);
    }

    public function show(Request $request, int $trip): JsonResponse
    {
        return response()->json(['data' => $this->present($this->mine($request)->findOrFail($trip))]);
    }

    /**
     * Finished trips, newest first (the trip in progress is `current`).
     */
    public function index(Request $request): JsonResponse
    {
        $finished = array_map(fn (TripStatus $status) => $status->value, array_filter(TripStatus::cases(), fn (TripStatus $status) => $status->isTerminal()));
        $page = $this->mine($request)->whereIn('status', $finished)->orderByDesc('requested_at')->orderByDesc('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => TripResource::collection($page->getCollection())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function cancel(CancelTripRequest $request, int $trip): JsonResponse
    {
        $found = $this->mine($request)->findOrFail($trip);

        try {
            $cancelled = $this->trips->cancelByRider($found, $request->reason());
        } catch (TripStateException $e) {
            return $this->conflict($e, $found, fn (Trip $current) => new TripResource($this->withRelations($current)));
        }

        return response()->json(['data' => $this->present($cancelled)]);
    }

    public function receipt(Request $request, int $trip): JsonResponse
    {
        $found = $this->mine($request)->findOrFail($trip);

        if ($found->status !== TripStatus::Completed) {
            return response()->json(['message' => __('The receipt is ready once the trip is completed.'), 'code' => 'trip_not_completed'], 409);
        }

        return response()->json(['data' => (new TripReceiptResource($found))->resolve()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Trip $trip): array
    {
        $trip = $this->withRelations($trip);

        return (new TripResource($trip))->withEta($this->eta->seconds($trip))->resolve();
    }

    private function withRelations(Trip $trip): Trip
    {
        return $trip->load(['driver.user:id,name', 'vehicle']);
    }

    /**
     * @return Builder<Trip>
     */
    private function mine(Request $request)
    {
        return Trip::query()->where('rider_id', $this->rider($request)->getKey())->with(['driver.user:id,name', 'vehicle']);
    }

    private function rider(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
