<?php

namespace App\Data;

/**
 * One line of an address search: what to show and the id to ask placeDetails() about.
 */
final readonly class GeoSuggestion
{
    public function __construct(
        public string $placeId,
        /** The whole line, as one string. */
        public string $text,
        /** The place's own name or the street (the bold part). */
        public string $primaryText,
        /** The rest of the address (the grey part), when there is one. */
        public ?string $secondaryText = null,
    ) {}

    /**
     * @return array{place_id: string, text: string, primary_text: string, secondary_text: ?string}
     */
    public function toArray(): array
    {
        return ['place_id' => $this->placeId, 'text' => $this->text, 'primary_text' => $this->primaryText, 'secondary_text' => $this->secondaryText];
    }
}
