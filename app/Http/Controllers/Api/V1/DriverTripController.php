<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripStatus;
use App\Exceptions\DriverStatusException;
use App\Exceptions\TripStateException;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithTrips;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelTripRequest;
use App\Http\Resources\Api\V1\DriverTripResource;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Services\Trips\TripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The driver's side of a trip (P7-T6): the trip they are on, their finished trips with what each earned, and the steps arrived, start,
 * complete and cancel. A driver only ever reaches trips assigned to them: any other trip is "not found", so one driver cannot learn that
 * another's trip exists. Every step takes an Idempotency-Key (the `idempotent` middleware); a step the trip's state does not allow is a 409
 * that carries the trip as it is now.
 */
class DriverTripController extends Controller
{
    use RespondsWithTrips;

    private const PER_PAGE = 20;

    public function __construct(private readonly TripService $trips) {}

    public function current(Request $request): JsonResponse|Response
    {
        $trip = $this->mine($request)->whereIn('status', [TripStatus::Matched->value, TripStatus::Arrived->value, TripStatus::InProgress->value])->first();

        return $trip === null ? response()->noContent() : response()->json(['data' => (new DriverTripResource($trip))->resolve()]);
    }

    /**
     * Completed and cancelled trips, newest first, each with its earnings.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $this->mine($request)->whereIn('status', [TripStatus::Completed->value, TripStatus::Cancelled->value])
            ->orderByRaw('coalesce(completed_at, cancelled_at) desc')->orderByDesc('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => DriverTripResource::collection($page->getCollection())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function arrived(Request $request, int $trip): JsonResponse
    {
        return $this->step($request, $trip, fn (Trip $found) => $this->trips->arrived($found));
    }

    public function start(Request $request, int $trip): JsonResponse
    {
        return $this->step($request, $trip, fn (Trip $found) => $this->trips->start($found));
    }

    public function complete(Request $request, int $trip): JsonResponse
    {
        return $this->step($request, $trip, fn (Trip $found) => $this->trips->complete($found));
    }

    public function cancel(CancelTripRequest $request, int $trip): JsonResponse
    {
        return $this->step($request, $trip, fn (Trip $found) => $this->trips->cancelByDriver($found, (string) $request->reason()));
    }

    /**
     * @param  callable(Trip): Trip  $change
     */
    private function step(Request $request, int $trip, callable $change): JsonResponse
    {
        $found = $this->mine($request)->findOrFail($trip);

        try {
            $changed = $change($found);
        } catch (TripStateException $e) {
            return $this->conflict($e, $found, fn (Trip $current) => new DriverTripResource($current->load('rider:id,name')));
        }

        return response()->json(['data' => (new DriverTripResource($changed->load('rider:id,name')))->resolve()]);
    }

    /**
     * @return Builder<Trip>
     */
    private function mine(Request $request)
    {
        $profile = DriverProfile::query()->where('user_id', $request->user()->getKey())->first() ?? throw DriverStatusException::notVerified();

        return Trip::query()->where('driver_id', $profile->getKey())->with('rider:id,name');
    }
}
