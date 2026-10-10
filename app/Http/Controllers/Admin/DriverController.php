<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DriverVerificationStatus;
use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DriverController extends Controller
{
    public const TABS = ['profile', 'documents', 'vehicles', 'earnings', 'trip-history', 'bank-accounts'];

    public function __construct(private readonly AdminFixtures $fixtures) {}

    public function index(Request $request): Response
    {
        $data = $this->fixtures->get('drivers');

        return Inertia::render('Drivers/Index', [
            'drivers' => FixtureTable::paginate($request, $data['rows'], ['name', 'phone', 'code'], ['verification_status'], ['name', 'trips_count', 'rating'], null),
            'filters' => FixtureTable::filters($request, ['verification_status']),
            'sort' => FixtureTable::sort($request, ['name', 'trips_count', 'rating']),
            'verificationStatuses' => array_column(DriverVerificationStatus::cases(), 'value'),
            'totalRegistered' => $data['total_registered'],
            'actions' => ['export' => null, 'create' => null],
        ]);
    }

    public function show(Request $request, int $driver): Response
    {
        $data = $this->fixtures->get('drivers');
        $row = collect($data['rows'])->firstWhere('id', $driver) ?? abort(404);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        return Inertia::render('Drivers/Show', [
            'driver' => [...$row, ...$data['detail']],
            'tab' => $tab,
            'tabs' => self::TABS,
            'actions' => [
                'approveDocument' => null, 'rejectDocument' => null, 'addVehicle' => null, 'setPrimaryVehicle' => null,
                'addAdjustment' => null, 'recordCashSettlement' => null, 'revealBankAccount' => null,
            ],
        ]);
    }
}
