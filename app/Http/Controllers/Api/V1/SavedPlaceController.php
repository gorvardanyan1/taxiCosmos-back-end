<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavedPlaceRequest;
use App\Http\Resources\Api\V1\SavedPlaceResource;
use App\Models\SavedPlace;
use App\Services\Account\SavedPlaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The rider's saved places (P3-T5). Every place is looked up through the signed-in rider's own rows, so another rider's id
 * is simply "not found".
 */
class SavedPlaceController extends Controller
{
    public function __construct(private readonly SavedPlaceService $places) {}

    public function index(Request $request): JsonResponse
    {
        // At most the configured cap (20), so there is nothing to paginate.
        return response()->json(['data' => SavedPlaceResource::collection(SavedPlace::query()->where('user_id', $request->user()->getKey())->orderBy('id')->get())->resolve()]);
    }

    public function store(SavedPlaceRequest $request): JsonResponse
    {
        $attributes = $request->placeAttributes();
        $place = $this->places->create($request->user(), $attributes['label'], $attributes['name'], $attributes['address'], $request->point());

        return response()->json(['data' => (new SavedPlaceResource($place))->resolve()], 201);
    }

    public function show(Request $request, int $place): JsonResponse
    {
        return response()->json(['data' => (new SavedPlaceResource($this->own($request, $place)))->resolve()]);
    }

    public function update(SavedPlaceRequest $request, int $place): JsonResponse
    {
        $updated = $this->places->update($this->own($request, $place), $request->placeAttributes(), $request->point());

        return response()->json(['data' => (new SavedPlaceResource($updated))->resolve()]);
    }

    public function destroy(Request $request, int $place): JsonResponse
    {
        $this->places->delete($this->own($request, $place));

        return response()->json(status: 204);
    }

    private function own(Request $request, int $id): SavedPlace
    {
        return SavedPlace::query()->where('user_id', $request->user()->getKey())->findOrFail($id);
    }
}
