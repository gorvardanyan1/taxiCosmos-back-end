<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DriverVerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Support\AdminFixtures;
use App\Support\DriverDetailPresenter;
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
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];
        $profile = DriverProfile::query()->find($driver);

        // Drivers in the database show real data; ids 1-8 are the fixture drivers until P4-T2
        // makes the whole page real.
        if ($profile === null) {
            $data = $this->fixtures->get('drivers');
            $row = collect($data['rows'])->firstWhere('id', $driver) ?? abort(404);
        }

        return Inertia::render('Drivers/Show', [
            'driver' => $profile === null ? [...$row, ...$data['detail']] : DriverDetailPresenter::present($profile),
            'tab' => $tab,
            'tabs' => self::TABS,
            'actions' => [
                'approveDocument' => $profile === null ? null : $this->documentUrl('approve', $profile),
                'rejectDocument' => $profile === null ? null : $this->documentUrl('reject', $profile),
                'addVehicle' => null, 'setPrimaryVehicle' => null,
                'addAdjustment' => null, 'recordCashSettlement' => null, 'revealBankAccount' => null,
            ],
        ]);
    }

    /**
     * URL template with an {id} placeholder for the document, as the page's row actions expect.
     */
    private function documentUrl(string $action, DriverProfile $profile): string
    {
        // The route only accepts numbers, so build it with 0 and swap in the placeholder.
        return str_replace('/documents/0/', '/documents/{id}/', route("admin.drivers.documents.{$action}", ['driver' => $profile->getKey(), 'document' => 0], false));
    }
}
