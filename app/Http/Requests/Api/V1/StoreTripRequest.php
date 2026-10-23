<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use App\Enums\TripPaymentMethod;
use App\Enums\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/trips. The rider is the signed-in one; the price comes from the quote, never from the body. The MVP takes cash only:
 * card and wallet are refused here with a message on the field (they need the payment holds that arrive with those gateways).
 */
class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'string', 'max:36'],
            'vehicle_class' => ['required', Rule::enum(VehicleClass::class)],
            'payment_method' => ['required', Rule::enum(TripPaymentMethod::class), Rule::in([TripPaymentMethod::Cash->value])],
            'pickup' => ['required', 'array:lat,lng,address'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup.address' => ['required', 'string', 'min:1', 'max:255'],
            'dropoff' => ['required', 'array:lat,lng,address'],
            'dropoff.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff.address' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['payment_method.in' => __('Only cash is available for now.')];
    }

    public function vehicleClass(): VehicleClass
    {
        return VehicleClass::from($this->validated('vehicle_class'));
    }

    public function paymentMethod(): TripPaymentMethod
    {
        return TripPaymentMethod::from($this->validated('payment_method'));
    }

    public function pickup(): GeoPoint
    {
        return new GeoPoint((float) $this->validated('pickup.lat'), (float) $this->validated('pickup.lng'));
    }

    public function dropoff(): GeoPoint
    {
        return new GeoPoint((float) $this->validated('dropoff.lat'), (float) $this->validated('dropoff.lng'));
    }
}
