<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends Controller
{
    public const STATUSES = ['pending', 'approved', 'paid', 'failed', 'rejected'];

    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('payouts');

        return Inertia::render('Payouts/Index', [
            'periods' => $data['periods'],
            'summary' => $data['summary'],
            'payouts' => FixtureTable::paginate($request, $data['rows'], ['driver'], ['status', 'period_start'], ['amount.amount', 'driver'], null),
            'filters' => FixtureTable::filters($request, ['status', 'period_start']),
            'statuses' => self::STATUSES,
            'skipped' => $data['skipped'],
            'actions' => ['exportBankCsv' => null, 'approveBatch' => null, 'approve' => null, 'reject' => null],
        ]);
    }
}
