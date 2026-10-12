<?php

namespace App\Http\Requests\Admin;

use App\Enums\CommissionType;
use App\Enums\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One version of a zone's commission (for one vehicle class, or every class when vehicle_class is empty).
 * Money is integer minor units of the zone's currency, which the server sets itself.
 */
class StoreCommissionRuleRequest extends FormRequest
{
    /** Far above any real commission, and low enough that rate x fare can never overflow a 64-bit integer. */
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
            'zone_id' => ['required', 'integer', Rule::exists('zones', 'id')],
            'vehicle_class' => ['nullable', Rule::enum(VehicleClass::class)],
            'type' => ['required', Rule::enum(CommissionType::class)],
            'rate_bps' => ['required', 'integer', 'between:0,10000'],
            'fixed_amount' => $money,
            'min_commission' => $money,
            'max_commission' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MONEY, 'gte:min_commission'],
            'applies_to_surge' => ['required', 'boolean'],
        ];
    }
}
