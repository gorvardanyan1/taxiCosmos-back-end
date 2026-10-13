<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/geo/places/{placeId}?session=
 */
class GeoPlaceRequest extends FormRequest
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
        return ['session' => GeoSessionToken::rules()];
    }

    public function searchSession(): ?string
    {
        return $this->validated('session');
    }
}
