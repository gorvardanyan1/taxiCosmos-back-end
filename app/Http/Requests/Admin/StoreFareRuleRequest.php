<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One version of a class's prices in a zone. Every term is sent (a new version is a full snapshot);
 * money is integer minor units of the zone's currency, which the server sets itself.
 */
class StoreFareRuleRequest extends FormRequest
{
    /** Far above any real fare, and low enough that price x distance can never overflow a 64-bit integer. */
    private const MAX_MONEY = 1_000_000_000;

    /**
     * Authorization is the route's permission middleware (fares.manage).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['required', 'integer', 'min:0', 'max:'.self::MAX_MONEY];

        return [
            'vehicle_class' => ['required', Rule::enum(VehicleClass::class)],
            'base_fare' => $money,
            'per_km' => $money,
            'per_minute' => $money,
            'min_fare' => $money,
            'waiting_free_minutes' => ['required', 'integer', 'between:0,240'],
            'waiting_per_minute' => $money,
            'cancellation_fee' => $money,
            'cancellation_free_seconds' => ['required', 'integer', 'between:0,86400'],
            'booking_fee' => $money,
            'tax_rate_bps' => ['required', 'integer', 'between:0,10000'],
            'tax_inclusive' => ['required', 'boolean'],
        ];
    }
}
