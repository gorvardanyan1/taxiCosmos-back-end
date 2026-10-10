<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VehicleClass;
use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SurgeController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('surge');

        return Inertia::render('Surge/Index', [
            'surges' => FixtureTable::paginate($request, $data['rows'], ['zone', 'reason'], ['vehicle_class'], ['multiplier'], null),
            'filters' => FixtureTable::filters($request, ['vehicle_class']),
            'zones' => $data['zones'],
            'vehicleClasses' => array_column(VehicleClass::cases(), 'value'),
            'maxMultiplier' => $data['max_multiplier'],
            'actions' => ['create' => null, 'end' => null],
        ]);
    }
}
