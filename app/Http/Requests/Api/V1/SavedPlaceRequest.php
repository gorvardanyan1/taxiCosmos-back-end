<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use App\Enums\SavedPlaceLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST and PATCH /api/v1/saved-places. Creating needs every field; changing sends only what changes (a position is always
 * lat and lng together). The owner is never taken from the request.
 */
class SavedPlaceRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => [$presence, Rule::enum(SavedPlaceLabel::class)],
            'name' => [$presence, 'string', 'min:1', 'max:80'],
            'address' => [$presence, 'string', 'min:1', 'max:255'],
            // A change may leave the position alone, but never half of it.
            'lat' => [$this->isMethod('POST') ? 'required' : 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => [$this->isMethod('POST') ? 'required' : 'required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array{label?: SavedPlaceLabel, name?: string, address?: string}
     */
    public function placeAttributes(): array
    {
        $validated = $this->safe()->only(['label', 'name', 'address']);

        if (isset($validated['label'])) {
            $validated['label'] = SavedPlaceLabel::from($validated['label']);
        }

        return $validated;
    }

    public function point(): ?GeoPoint
    {
        return $this->has('lat') ? new GeoPoint((float) $this->validated('lat'), (float) $this->validated('lng')) : null;
    }
}
