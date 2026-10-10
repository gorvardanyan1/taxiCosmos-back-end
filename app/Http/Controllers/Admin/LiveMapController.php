<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DriverAvailability;
use App\Enums\VehicleClass;
use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LiveMapController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('live-map');
        $filters = [
            'zone' => in_array((int) $request->query('zone'), array_column($data['zones'], 'id'), true) ? (int) $request->query('zone') : null,
            'vehicle_class' => VehicleClass::tryFrom((string) $request->query('vehicle_class'))?->value,
            'availability' => DriverAvailability::tryFrom((string) $request->query('availability'))?->value,
        ];

        $markers = array_values(array_filter(
            $data['markers'],
            fn (array $marker) => $filters['availability'] === null || $marker['availability'] === $filters['availability'],
        ));

        return Inertia::render('LiveMap', [...$data, 'markers' => $markers, 'filters' => $filters]);
    }
}
