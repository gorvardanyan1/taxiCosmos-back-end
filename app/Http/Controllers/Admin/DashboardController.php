<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Each alert card links to a page, so it goes only to admins who may open that page (the count would otherwise leak). */
    private const ALERT_PERMISSIONS = ['stuck_trips' => 'trips.view', 'failed_payments' => 'payments.view', 'urgent_tickets' => 'support.manage'];

    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('dashboard');
        $data['alerts'] = array_filter($data['alerts'], fn (string $key) => $request->user()->can(self::ALERT_PERMISSIONS[$key]), ARRAY_FILTER_USE_KEY);
        $zoneIds = array_column($data['zones'], 'id');
        $zone = in_array((int) $request->query('zone'), $zoneIds, true) ? (int) $request->query('zone') : null;

        return Inertia::render('Dashboard', [...$data, 'filters' => ['zone' => $zone]]);
    }
}
