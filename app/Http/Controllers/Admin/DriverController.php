<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DriverAvailability;
use App\Enums\DriverVerificationStatus;
use App\Enums\VehicleClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DriverStatusRequest;
use App\Http\Requests\Admin\UpdateDriverRequest;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Zone;
use App\Queries\DriverQuery;
use App\Services\Drivers\DriverService;
use App\Support\DriverDetailPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class DriverController extends Controller
{
    public const TABS = ['profile', 'documents', 'vehicles', 'earnings', 'trip-history', 'bank-accounts'];

    private const FILTERS = ['search', 'verification_status', 'availability', 'zone', 'vehicle_class', 'license_number', 'documents_expiring'];

    public function __construct(private readonly DriverService $drivers) {}

    public function index(Request $request): Response
    {
        $drivers = DriverQuery::for($request)
            ->paginate($this->perPage($request))
            ->appends(Arr::except($request->query(), 'page'))
            ->through(fn (DriverProfile $driver) => DriverDetailPresenter::row($driver));

        return Inertia::render('Drivers/Index', [
            'drivers' => $drivers,
            'filters' => array_filter(Arr::only((array) $request->query('filter', []), self::FILTERS), 'is_string'),
            'sort' => is_string($request->query('sort')) ? $request->query('sort') : null,
            'verificationStatuses' => array_column(DriverVerificationStatus::cases(), 'value'),
            'availabilities' => array_column(DriverAvailability::cases(), 'value'),
            'vehicleClasses' => array_column(VehicleClass::cases(), 'value'),
            'zones' => $this->zones(),
            'totalRegistered' => DriverProfile::query()->count(),
            // Drivers sign up in the mobile app (P4-T4), so there is no admin "add driver"; export arrives with reports (P13-T9).
            'actions' => [
                'export' => null,
                'create' => null,
                'suspend' => $this->can($request, 'drivers.suspend') ? '/admin/drivers/{id}/suspend' : null,
                'reactivate' => $this->can($request, 'drivers.suspend') ? '/admin/drivers/{id}/reactivate' : null,
            ],
        ]);
    }

    public function show(Request $request, int $driver): Response
    {
        return $this->render($request, $driver, in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0]);
    }

    /**
     * GET /admin/drivers/{id}/vehicles: the driver page on its Vehicles tab.
     */
    public function vehicles(Request $request, int $driver): Response
    {
        return $this->render($request, $driver, 'vehicles');
    }

    public function update(UpdateDriverRequest $request, int $driver): RedirectResponse
    {
        $changed = $this->drivers->update($this->admin($request), DriverProfile::query()->findOrFail($driver), $request->safe()->except('reason'), $request->validated('reason'));

        return back()->with('success', $changed ? __('Driver updated.') : __('Nothing to change.'));
    }

    public function suspend(DriverStatusRequest $request, int $driver): RedirectResponse
    {
        $this->drivers->suspend($this->admin($request), DriverProfile::query()->findOrFail($driver), $request->validated('reason'));

        return back()->with('success', __('Driver suspended.'));
    }

    public function reactivate(DriverStatusRequest $request, int $driver): RedirectResponse
    {
        $this->drivers->reactivate($this->admin($request), DriverProfile::query()->findOrFail($driver), $request->validated('reason'));

        return back()->with('success', __('Driver reactivated.'));
    }

    private function render(Request $request, int $driver, string $tab): Response
    {
        $profile = DriverProfile::query()->findOrFail($driver);

        return Inertia::render('Drivers/Show', [
            'driver' => DriverDetailPresenter::present($profile),
            'tab' => $tab,
            'tabs' => self::TABS,
            'zones' => $this->zones(),
            'vehicleClasses' => array_column(VehicleClass::cases(), 'value'),
            'actions' => [
                'approveDocument' => $this->can($request, 'drivers.verify') ? $this->template('approve', $profile) : null,
                'rejectDocument' => $this->can($request, 'drivers.verify') ? $this->template('reject', $profile) : null,
                'edit' => $this->can($request, 'drivers.edit') ? route('admin.drivers.update', $profile->id, absolute: false) : null,
                'suspend' => $this->can($request, 'drivers.suspend') ? route('admin.drivers.suspend', $profile->id, absolute: false) : null,
                'reactivate' => $this->can($request, 'drivers.suspend') ? route('admin.drivers.reactivate', $profile->id, absolute: false) : null,
                'addVehicle' => $this->can($request, 'drivers.edit') ? route('admin.drivers.vehicles.store', $profile->id, absolute: false) : null,
                'updateVehicle' => $this->can($request, 'drivers.edit') ? $this->vehicleTemplate('admin.drivers.vehicles.update', $profile) : null,
                'setPrimaryVehicle' => $this->can($request, 'drivers.edit') ? $this->vehicleTemplate('admin.drivers.vehicles.set-primary', $profile) : null,
                // The full number is revealed (and logged) only for roles with the permission, through this endpoint.
                'revealBankAccount' => $this->can($request, 'drivers.reveal_bank_account') ? $this->accountTemplate($profile) : null,
                // The earnings ledger (P11-T2) and cash settlements (P11-T3) are not built yet.
                'addAdjustment' => null, 'recordCashSettlement' => null,
            ],
        ]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function zones(): array
    {
        return Zone::query()->orderBy('name')->get(['id', 'name'])->map(fn (Zone $zone) => ['id' => $zone->id, 'name' => $zone->name])->all();
    }

    /**
     * URL template with an {id} placeholder for the document, as the page's row actions expect.
     */
    private function template(string $action, DriverProfile $profile): string
    {
        // The route only accepts numbers, so build it with 0 and swap in the placeholder.
        return str_replace('/documents/0/', '/documents/{id}/', route("admin.drivers.documents.{$action}", ['driver' => $profile->getKey(), 'document' => 0], false));
    }

    private function vehicleTemplate(string $route, DriverProfile $profile): string
    {
        return str_replace('/vehicles/0', '/vehicles/{id}', route($route, ['driver' => $profile->getKey(), 'vehicle' => 0], false));
    }

    private function accountTemplate(DriverProfile $profile): string
    {
        return str_replace('/bank-accounts/0/', '/bank-accounts/{id}/', route('admin.drivers.bank-accounts.reveal', ['driver' => $profile->getKey(), 'account' => 0], false));
    }

    private function can(Request $request, string $permission): bool
    {
        return (bool) $request->user()?->can($permission);
    }

    private function perPage(Request $request): int
    {
        $options = config('taxikosmos.admin.per_page_options');
        $requested = (int) $request->query('per_page');

        return in_array($requested, $options, true) ? $requested : $options[0];
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
