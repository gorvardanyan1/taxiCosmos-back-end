<?php

namespace App\Services\Fares;

use App\Data\FareBreakdown;
use App\Enums\TripCancelledBy;
use App\Models\FareRule;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Prices a trip from one fare rule. The rider's estimate and the final fare both come from here (the
 * final fare with the real distance, time and waiting), so they can only differ by their inputs.
 *
 * Every amount is an integer in minor units of the rule's currency; there are no floats. A product
 * such as per_km x metres is rounded half-up to a whole minor unit (so a currency with 2 decimals
 * is rounded to the cent, one with none to the unit) once per component, which makes the printed
 * components add up to the total exactly.
 *
 *   metered  = base + distance + time + waiting
 *   surge    = metered x (multiplier - 1)            (the booking fee, place fees and tax are not surged)
 *   minimum  = whatever lifts metered + surge up to the rule's minimum fare
 *   subtotal = metered + surge + minimum + booking fee + place fees
 *   tax      = subtotal x rate            (exclusive: added on top)
 *            = subtotal x rate / (1 + rate)   (inclusive: the part of the subtotal that is tax)
 */
final class FareCalculator
{
    private const METERS_PER_KM = 1000;

    private const SECONDS_PER_MINUTE = 60;

    private const SURGE_NONE = 100;

    /**
     * @param  int  $distanceMeters  the route length (estimate) or the driven distance (final fare)
     * @param  int  $durationSeconds  the driving time
     * @param  int  $waitingSeconds  time the driver waited at the pickup; the rule's free minutes are taken off
     * @param  int  $surgeHundredths  the surge multiplier x 100 (150 = 1.50x); capped by config and never below 1.00
     * @param  iterable<object{name: string, amount: int}>  $fees  fixed surcharges that apply to this trip's places
     */
    public function calculate(
        FareRule $rule,
        int $distanceMeters,
        int $durationSeconds,
        int $waitingSeconds = 0,
        int $surgeHundredths = self::SURGE_NONE,
        iterable $fees = [],
    ): FareBreakdown {
        if ($distanceMeters < 0 || $durationSeconds < 0 || $waitingSeconds < 0) {
            throw new InvalidArgumentException('Distance, duration and waiting time cannot be negative.');
        }

        $surgeHundredths = max(self::SURGE_NONE, min($surgeHundredths, (int) config('taxikosmos.fares.surge_cap_hundredths')));

        $distance = $this->ratio($rule->per_km, $distanceMeters, self::METERS_PER_KM);
        $time = $this->ratio($rule->per_minute, $durationSeconds, self::SECONDS_PER_MINUTE);
        $billableWaiting = max(0, $waitingSeconds - $rule->waiting_free_minutes * self::SECONDS_PER_MINUTE);
        $waiting = $this->ratio($rule->waiting_per_minute, $billableWaiting, self::SECONDS_PER_MINUTE);

        $metered = $rule->base_fare + $distance + $time + $waiting;
        $surge = $this->ratio($metered, $surgeHundredths - self::SURGE_NONE, self::SURGE_NONE);
        $minimumAdjustment = max(0, $rule->min_fare - ($metered + $surge));

        $placeFees = [];
        foreach ($fees as $fee) {
            $placeFees[] = ['name' => (string) $fee->name, 'amount' => (int) $fee->amount];
        }

        $subtotal = $metered + $surge + $minimumAdjustment + $rule->booking_fee + array_sum(array_column($placeFees, 'amount'));

        if ($rule->tax_inclusive) {
            $tax = $this->ratio($subtotal, $rule->tax_rate_bps, 10_000 + $rule->tax_rate_bps);
            $total = $subtotal;
        } else {
            $tax = $this->ratio($subtotal, $rule->tax_rate_bps, 10_000);
            $total = $subtotal + $tax;
        }

        return new FareBreakdown(
            currency: $rule->currency,
            base: $rule->base_fare,
            distance: $distance,
            time: $time,
            waiting: $waiting,
            minimumAdjustment: $minimumAdjustment,
            bookingFee: $rule->booking_fee,
            fees: $placeFees,
            surgeMultiplier: sprintf('%d.%02d', intdiv($surgeHundredths, 100), $surgeHundredths % 100),
            surge: $surge,
            subtotal: $subtotal,
            taxRateBps: $rule->tax_rate_bps,
            taxInclusive: $rule->tax_inclusive,
            tax: $tax,
            total: $total,
        );
    }

    /**
     * What a rider owes for cancelling: the rule's cancellation fee when they cancel later than
     * cancellation_free_seconds after a driver was matched, nothing before that, nothing when no
     * driver was matched yet, and nothing when the driver, an admin or the system cancels.
     */
    public function cancellationFee(FareRule $rule, TripCancelledBy $cancelledBy, ?CarbonInterface $matchedAt, CarbonInterface $cancelledAt): int
    {
        if ($cancelledBy !== TripCancelledBy::Rider || $matchedAt === null) {
            return 0;
        }

        return $matchedAt->diffInSeconds($cancelledAt, absolute: false) > $rule->cancellation_free_seconds ? $rule->cancellation_fee : 0;
    }

    /**
     * What a rider owes when the driver marks a no-show: the cancellation fee once the driver has
     * waited the rule's waiting_free_minutes or longer, nothing before that.
     */
    public function noShowFee(FareRule $rule, CarbonInterface $arrivedAt, CarbonInterface $markedAt): int
    {
        return $arrivedAt->diffInSeconds($markedAt, absolute: false) >= $rule->waiting_free_minutes * self::SECONDS_PER_MINUTE ? $rule->cancellation_fee : 0;
    }

    /**
     * The breakdown stored on a cancelled trip: no ride, only the cancellation fee (no tax is added to it).
     */
    public function cancellationBreakdown(FareRule $rule, int $fee): FareBreakdown
    {
        return new FareBreakdown(
            currency: $rule->currency, base: 0, distance: 0, time: 0, waiting: 0, minimumAdjustment: 0, bookingFee: 0, fees: [],
            surgeMultiplier: '1.00', surge: 0, subtotal: $fee, taxRateBps: 0, taxInclusive: false, tax: 0, total: $fee, cancellationFee: $fee,
        );
    }

    /**
     * $amount x $numerator / $denominator, rounded half-up (all non-negative).
     */
    private function ratio(int $amount, int $numerator, int $denominator): int
    {
        return intdiv($amount * $numerator * 2 + $denominator, $denominator * 2);
    }
}
