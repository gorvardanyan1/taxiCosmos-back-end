<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/geo/autocomplete?q=&lat=&lng=&session=
 */
class GeoAutocompleteRequest extends FormRequest
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
            // Two characters at least: one letter would only buy a useless, billed answer.
            'q' => ['required', 'string', 'min:2', 'max:120'],
            // Where the user is, to rank nearby places first; both or neither.
            'lat' => ['required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'session' => GeoSessionToken::rules(),
        ];
    }

    public function text(): string
    {
        return (string) $this->validated('q');
    }

    public function near(): ?GeoPoint
    {
        return $this->filled('lat') ? new GeoPoint((float) $this->validated('lat'), (float) $this->validated('lng')) : null;
    }

    public function searchSession(): ?string
    {
        return $this->validated('session');
    }
}
