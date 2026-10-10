<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreZoneRequest;
use App\Http\Requests\Admin\UpdateZoneRequest;
use App\Http\Requests\Admin\ZoneStatusRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function __construct(private readonly ZoneService $zones) {}

    public function index(Request $request): Response
    {
        $zones = Zone::query()->withGeometry()->orderByDesc('priority')->orderBy('name')->orderBy('id')->get();
        $selected = $zones->firstWhere('id', (int) $request->query('zone')) ?? $zones->first();

        return Inertia::render('Zones/Index', [
            'zones' => $zones->map(fn (Zone $zone) => $this->present($zone))->all(),
            'selectedZoneId' => $selected?->id,
            // Only the selected zone's shape goes to the browser (a polygon can have thousands of points).
            'selectedPolygon' => $selected === null ? null : json_decode($selected->polygon_geojson, true),
            // Fare rules arrive with P5-T2.
            'fareRules' => [],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'currencies' => array_column(config('taxikosmos.currencies'), 'code'),
            'actions' => [
                'createZone' => route('admin.zones.store', absolute: false),
                'updateZone' => $this->template('admin.zones.update'),
                'deactivateZone' => $this->template('admin.zones.deactivate'),
                'activateZone' => $this->template('admin.zones.activate'),
                'updateFareRule' => null,
                'drawPolygon' => null,
            ],
        ]);
    }

    public function store(StoreZoneRequest $request): RedirectResponse
    {
        $zone = $this->zones->create($this->admin($request), $request->validated());

        return redirect()->route('admin.zones.index', ['zone' => $zone->id])->with('success', 'Zone created.');
    }

    public function update(UpdateZoneRequest $request, int $zone): RedirectResponse
    {
        $this->zones->update($this->admin($request), Zone::query()->findOrFail($zone), $request->validated());

        return back()->with('success', 'Zone updated.');
    }

    public function deactivate(ZoneStatusRequest $request, int $zone): RedirectResponse
    {
        $this->zones->deactivate($this->admin($request), Zone::query()->findOrFail($zone), $request->validated('reason'));

        return back()->with('success', 'Zone deactivated.');
    }

    public function activate(ZoneStatusRequest $request, int $zone): RedirectResponse
    {
        $this->zones->activate($this->admin($request), Zone::query()->findOrFail($zone), $request->validated('reason'));

        return back()->with('success', 'Zone activated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Zone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'code' => $zone->code,
            'status' => $zone->is_active ? 'active' : 'inactive',
            // The database refuses invalid shapes, so every stored polygon is valid.
            'polygon_valid' => true,
            'timezone' => $zone->timezone,
            'currency' => $zone->currency,
            'priority' => $zone->priority,
            'points' => (int) $zone->polygon_points,
        ];
    }

    /**
     * URL template with an {id} placeholder, as the page's row actions expect.
     */
    private function template(string $route): string
    {
        // The routes only accept numbers: build with 0 and swap in the placeholder.
        return str_replace('/zones/0', '/zones/{id}', route($route, ['zone' => 0], false));
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
