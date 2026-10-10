<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('zones');
        $selected = collect($data['zones'])->firstWhere('id', (int) $request->query('zone')) ?? $data['zones'][0];

        return Inertia::render('Zones/Index', [
            'zones' => $data['zones'],
            'selectedZoneId' => $selected['id'],
            'fareRules' => array_values(array_filter($data['fare_rules'], fn (array $rule) => $rule['zone_id'] === $selected['id'])),
            'actions' => ['createZone' => null, 'updateFareRule' => null, 'drawPolygon' => null],
        ]);
    }
}
