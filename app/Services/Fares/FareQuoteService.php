<?php

namespace App\Services\Fares;

use App\Data\FareBreakdown;
use App\Enums\VehicleClass;
use App\Exceptions\FareException;
use App\Models\FareQuote;
use App\Models\User;

/**
 * Reads back a quote when a trip is created from it (P7-T6). Only the rider the quote was made for
 * can use it, and only until it expires; anything else looks the same as "no such quote".
 */
final class FareQuoteService
{
    /**
     * @throws FareException quote_not_found (unknown, or someone else's), quote_expired
     */
    public function usable(string $quoteId, User $rider): FareQuote
    {
        $quote = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $quoteId) === 1
            ? FareQuote::query()->whereKey($quoteId)->where('rider_id', $rider->id)->first()
            : null;

        if ($quote === null) {
            throw FareException::quoteNotFound();
        }

        if ($quote->isExpired()) {
            throw FareException::quoteExpired();
        }

        return $quote;
    }

    /**
     * The pricing snapshot of one class in the quote: the rule id, surge and breakdown the trip is priced with.
     *
     * @return array{fare_rule_id: int, currency: string, amount: int, surge_multiplier: string, breakdown: FareBreakdown}
     *
     * @throws FareException vehicle_class_not_quoted
     */
    public function snapshot(FareQuote $quote, VehicleClass $class): array
    {
        $estimate = collect($quote->estimates)->firstWhere('vehicle_class', $class->value) ?? throw FareException::vehicleClassNotQuoted();

        return [
            'fare_rule_id' => $estimate['fare_rule_id'],
            'currency' => $estimate['currency'],
            'amount' => $estimate['amount'],
            'surge_multiplier' => $estimate['surge_multiplier'],
            'breakdown' => FareBreakdown::fromArray($estimate['breakdown']),
        ];
    }
}
