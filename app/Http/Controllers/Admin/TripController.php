<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TripController extends Controller
{
    /** Trip statuses (P2-T4). */
    public const STATUSES = ['requested', 'matched', 'arrived', 'in_progress', 'completed', 'cancelled', 'no_driver_found'];

    public function __construct(private readonly AdminFixtures $fixtures) {}

    public function index(Request $request): Response
    {
        $rows = $this->fixtures->get('trips')['rows'];

        return Inertia::render('Trips/Index', [
            'trips' => FixtureTable::paginate($request, $rows, ['code', 'rider', 'driver'], ['status'], ['requested_at', 'code'], '-requested_at'),
            'filters' => FixtureTable::filters($request, ['status']),
            'sort' => FixtureTable::sort($request, ['requested_at', 'code']),
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(int $trip): Response
    {
        $row = collect($this->fixtures->get('trips')['rows'])->firstWhere('id', $trip) ?? abort(404);

        return Inertia::render('Trips/Show', [
            'trip' => $row,
            'actions' => ['forceCancel' => null, 'adjustFare' => null, 'reassignDriver' => null],
        ]);
    }
}
