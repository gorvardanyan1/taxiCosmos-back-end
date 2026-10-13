<?php

namespace App\Data;

/**
 * A place or an address with its coordinates, the same shape whichever provider answered.
 */
final readonly class GeoPlace
{
    public function __construct(
        public ?string $placeId,
        public ?string $name,
        public string $address,
        public GeoPoint $location,
    ) {}

    /**
     * @return array{place_id: ?string, name: ?string, address: string, location: array{lat: float, lng: float}}
     */
    public function toArray(): array
    {
        return [
            'place_id' => $this->placeId,
            'name' => $this->name,
            'address' => $this->address,
            'location' => ['lat' => $this->location->latitude, 'lng' => $this->location->longitude],
        ];
    }

    /**
     * @param  array{place_id: ?string, name: ?string, address: string, location: array{lat: float, lng: float}}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['place_id'], $data['name'], $data['address'], new GeoPoint($data['location']['lat'], $data['location']['lng']));
    }
}
