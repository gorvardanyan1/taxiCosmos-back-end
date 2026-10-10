<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DriverVerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Support\AdminFixtures;
use App\Support\DriverDocumentPresenter;
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
        $profile = DriverProfile::query()->with(['documents' => fn ($q) => $q->with('reviewer:id,name')->orderBy('id')])->find($driver);
        $row = collect($data['rows'])->firstWhere('id', $driver) ?? ($profile === null ? abort(404) : null);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        return Inertia::render('Drivers/Show', [
            'driver' => [...$row ?? [], ...$data['detail'], ...($profile === null ? [] : $this->realDocuments($profile))],
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
     * The Documents tab and the verification badge come from the database once the driver exists
     * there; the rest of the page stays on fixtures until P4-T2 / P11-T2.
     *
     * @return array<string, mixed>
     */
    private function realDocuments(DriverProfile $profile): array
    {
        return [
            'verification_status' => $profile->verification_status->value,
            'documents' => $profile->documents->map(DriverDocumentPresenter::present(...))->all(),
        ];
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
