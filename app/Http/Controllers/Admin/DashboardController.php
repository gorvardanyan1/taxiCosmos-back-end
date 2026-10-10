<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('dashboard');
        $zoneIds = array_column($data['zones'], 'id');
        $zone = in_array((int) $request->query('zone'), $zoneIds, true) ? (int) $request->query('zone') : null;

        return Inertia::render('Dashboard', [...$data, 'filters' => ['zone' => $zone]]);
    }
}
