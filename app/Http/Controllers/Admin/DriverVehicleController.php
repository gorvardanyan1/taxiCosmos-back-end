<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveVehicleRequest;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Drivers\DriverVehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DriverVehicleController extends Controller
{
    public function __construct(private readonly DriverVehicleService $vehicles) {}

    public function store(SaveVehicleRequest $request, int $driver): RedirectResponse
    {
        $this->vehicles->add($this->admin($request), DriverProfile::query()->findOrFail($driver), $request->validated());

        return back()->with('success', __('Vehicle added.'));
    }

    public function update(SaveVehicleRequest $request, int $driver, int $vehicle): RedirectResponse
    {
        $profile = DriverProfile::query()->findOrFail($driver);
        $changed = $this->vehicles->update($this->admin($request), $profile, $profile->vehicles()->findOrFail($vehicle), $request->validated());

        return back()->with('success', $changed ? __('Vehicle updated.') : __('Nothing to change.'));
    }

    public function setPrimary(Request $request, int $driver, int $vehicle): RedirectResponse
    {
        $profile = DriverProfile::query()->findOrFail($driver);
        $this->vehicles->setPrimary($this->admin($request), $profile, $profile->vehicles()->findOrFail($vehicle));

        return back()->with('success', __('Primary vehicle changed.'));
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
