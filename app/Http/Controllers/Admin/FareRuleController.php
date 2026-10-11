<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VehicleClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFareRuleRequest;
use App\Models\FareRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\Fares\FareRuleService;
use Illuminate\Http\RedirectResponse;

class FareRuleController extends Controller
{
    public function __construct(private readonly FareRuleService $rules) {}

    /**
     * Creating the first rule of a class and changing a price are the same call: either adds a version.
     */
    public function store(StoreFareRuleRequest $request, int $zone): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $saved = $this->rules->saveVersion(
            $admin,
            Zone::query()->findOrFail($zone),
            VehicleClass::from($request->validated('vehicle_class')),
            $request->safe()->only(FareRule::TERMS),
        );

        return back()->with('success', $saved['created']
            ? __('Fare rule saved. The new prices apply from now.')
            : __('Nothing changed: these prices are already in force.'));
    }
}
