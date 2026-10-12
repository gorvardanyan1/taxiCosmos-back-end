<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * How a fare was put together, in integer minor units of one currency. It is what the rider is
 * shown, and what is stored on the trip (`trips.fare_breakdown`), so it only holds plain values.
 *
 *   subtotal = base + distance + time + waiting + surge + minimum_adjustment + booking_fee + fees
 *   total    = subtotal (tax already inside)  or  subtotal + tax
 *
 * The surge multiplier is a decimal string ("1.50"), never a float.
 */
final readonly class FareBreakdown
{
    /**
     * @param  list<array{name: string, amount: int}>  $fees  fixed surcharges (airport, tolls)
     */
    public function __construct(
        public string $currency,
        public int $base,
        public int $distance,
        public int $time,
        public int $waiting,
        public int $minimumAdjustment,
        public int $bookingFee,
        public array $fees,
        public string $surgeMultiplier,
        public int $surge,
        public int $subtotal,
        public int $taxRateBps,
        public bool $taxInclusive,
        public int $tax,
        public int $total,
        public int $cancellationFee = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'base' => $this->base,
            'distance' => $this->distance,
            'time' => $this->time,
            'waiting' => $this->waiting,
            'minimum_adjustment' => $this->minimumAdjustment,
            'booking_fee' => $this->bookingFee,
            'fees' => $this->fees,
            'surge_multiplier' => $this->surgeMultiplier,
            'surge' => $this->surge,
            'subtotal' => $this->subtotal,
            'tax_rate_bps' => $this->taxRateBps,
            'tax_inclusive' => $this->taxInclusive,
            'tax' => $this->tax,
            'total' => $this->total,
            'cancellation_fee' => $this->cancellationFee,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  the shape toArray() writes
     */
    public static function fromArray(array $data): self
    {
        foreach (['currency', 'base', 'distance', 'time', 'waiting', 'minimum_adjustment', 'booking_fee', 'fees', 'surge_multiplier', 'surge', 'subtotal', 'tax_rate_bps', 'tax_inclusive', 'tax', 'total'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("A fare breakdown needs {$key}.");
            }
        }

        return new self(
            $data['currency'], (int) $data['base'], (int) $data['distance'], (int) $data['time'], (int) $data['waiting'],
            (int) $data['minimum_adjustment'], (int) $data['booking_fee'], array_values($data['fees']), (string) $data['surge_multiplier'],
            (int) $data['surge'], (int) $data['subtotal'], (int) $data['tax_rate_bps'], (bool) $data['tax_inclusive'],
            (int) $data['tax'], (int) $data['total'], (int) ($data['cancellation_fee'] ?? 0),
        );
    }
}
