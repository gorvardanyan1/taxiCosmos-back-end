<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DriverVerificationStatus;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverDocuments\VerificationResolver;
use App\Support\E164PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in person as the apps see them: the one shape behind GET /api/v1/me and the `user` of every sign-in answer
 * (P3-T5). The apps read `status` and the driver block to choose between the normal app and a blocking screen. The phone is
 * masked; nothing here is a secret. Fixtures of every shape: tests/Fixtures/api/me.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone === null ? null : E164PhoneNumber::mask($this->phone),
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'modes' => ['is_rider' => $this->is_rider, 'is_driver' => $this->is_driver],
            'driver' => $this->is_driver || $this->driverProfile !== null ? $this->driverBlock($this->driverProfile) : null,
            'rider' => $this->is_rider ? $this->riderBlock() : null,
        ];
    }

    /**
     * Without a profile (applied as a driver, nothing uploaded yet) the driver is pending with every document missing.
     *
     * @return array<string, mixed>
     */
    private function driverBlock(?DriverProfile $profile): array
    {
        $profile ??= new DriverProfile;
        $vehicle = $profile->exists ? $profile->primaryVehicle : null;

        return [
            'verification_status' => ($profile->verification_status ?? DriverVerificationStatus::Pending)->value,
            'availability' => $profile->availability->value,
            // Required documents not yet approved, each with where it stands (missing, pending, rejected with the reviewer's reason, expired).
            'documents' => array_map(fn (array $document) => [
                'type' => $document['type']->value,
                'status' => $document['state']->value,
                'reason' => $document['reason'],
            ], app(VerificationResolver::class)->outstanding($profile)),
            'primary_vehicle' => $vehicle === null ? null : $this->vehicleBlock($vehicle),
            // Set once cash trips run up a balance (P11-T3); until then no driver can be over the limit.
            'debt_limit_reached' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicleBlock(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'plate_number' => $vehicle->plate_number,
            'color' => $vehicle->color,
            'vehicle_class' => $vehicle->vehicle_class->value,
            'status' => $vehicle->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function riderBlock(): array
    {
        return [
            'rating' => ['average' => $this->rider_rating_avg === null ? null : (float) $this->rider_rating_avg, 'count' => $this->rider_rating_count],
            // Money in minor units. Outstanding balances and saved cards arrive with payments (P6): nothing is owed and no card is saved before then.
            'outstanding_balance' => ['amount' => 0, 'currency' => config('taxikosmos.base_currency')],
            'default_payment_method' => null,
        ];
    }
}
