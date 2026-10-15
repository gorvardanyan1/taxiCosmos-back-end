<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentRequirementState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverDocumentRequest;
use App\Http\Resources\Api\V1\DriverDocumentResource;
use App\Models\DriverDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverDocuments\VerificationResolver;
use App\Services\Drivers\DriverOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The driver's own documents (P4-T4). GET lists each document with its status and the reason it was rejected, and what is still
 * missing; POST uploads one (replacing the earlier pending or rejected document of the same type). Files stay on the private disk:
 * the app never gets a link to one.
 */
class DriverDocumentController extends Controller
{
    public function __construct(private readonly DriverOnboardingService $onboarding) {}

    public function index(Request $request, VerificationResolver $resolver): JsonResponse
    {
        $profile = $this->onboarding->profileOf($this->user($request));
        $outstanding = $resolver->outstanding($profile);

        return response()->json([
            'data' => DriverDocumentResource::collection(DriverDocument::query()->where('driver_id', $profile->id)->orderBy('id')->get())->resolve(),
            // What the driver still has to do: each required document not yet approved and where it stands.
            'required' => array_map(fn (array $item) => ['type' => $item['type']->value, 'status' => $item['state']->value, 'reason' => $item['reason']], $outstanding),
            // True once every required document has been uploaded and none is rejected or expired: the rest is waiting for review.
            'all_submitted' => array_filter($outstanding, fn (array $item) => in_array($item['state'], [DocumentRequirementState::Missing, DocumentRequirementState::Rejected, DocumentRequirementState::Expired], true)) === [],
        ]);
    }

    public function store(DriverDocumentRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->onboarding->profileOf($user);
        $vehicleId = $request->validated('vehicle_id');
        $vehicle = $vehicleId === null ? null : Vehicle::query()->where('driver_id', $profile->id)->find($vehicleId);

        if ($vehicleId !== null && $vehicle === null) {
            throw ValidationException::withMessages(['vehicle_id' => __('The vehicle does not belong to this driver.')]);
        }

        $document = $this->onboarding->uploadDocument($user, $profile, $request->type(), $request->file('file'), $vehicle, $request->validated('document_number'), $request->expiresAt());

        return response()->json(['data' => (new DriverDocumentResource($document->refresh()))->resolve()], 201);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
