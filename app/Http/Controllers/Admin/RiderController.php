<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiderController extends Controller
{
    public const TABS = ['trips', 'payments', 'payment-methods', 'tickets', 'ratings', 'activity'];

    public function __construct(private readonly AdminFixtures $fixtures) {}

    public function index(Request $request): Response
    {
        $data = $this->fixtures->get('riders');

        return Inertia::render('Riders/Index', [
            'riders' => FixtureTable::paginate($request, $data['rows'], ['name', 'phone', 'email', 'code'], ['status'], ['name', 'trips_count', 'registered_at'], '-registered_at'),
            'filters' => FixtureTable::filters($request, ['status']),
            'sort' => FixtureTable::sort($request, ['name', 'trips_count', 'registered_at']),
            'statuses' => array_column(UserStatus::cases(), 'value'),
            'totalRegistered' => $data['total_registered'],
            'actions' => ['export' => null, 'invite' => null, 'suspend' => null],
        ]);
    }

    public function show(Request $request, int $rider): Response
    {
        $data = $this->fixtures->get('riders');
        $row = collect($data['rows'])->firstWhere('id', $rider) ?? abort(404);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        return Inertia::render('Riders/Show', [
            'rider' => [...$row, ...$data['detail']],
            'tab' => $tab,
            'tabs' => self::TABS,
            'actions' => ['edit' => null, 'suspend' => null],
        ]);
    }
}
