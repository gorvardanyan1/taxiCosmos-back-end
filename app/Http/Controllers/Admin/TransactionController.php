<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('transactions');
        $transactions = FixtureTable::paginate($request, $data['rows'], ['code', 'trip_code'], ['gateway', 'status'], ['created_at'], '-created_at');

        return Inertia::render('Transactions/Index', [
            'summary' => $data['summary'],
            'transactions' => $transactions,
            'filters' => FixtureTable::filters($request, ['gateway', 'status']),
            // Detail drawer for ?txn=<id>, looked up across all rows so a deep link works from any page.
            'selected' => collect($data['rows'])->firstWhere('id', (int) $request->query('txn')),
            'gateways' => ['stripe', 'authorize_net', 'manual', 'wallet'],
            'actions' => ['recordManual' => null, 'refund' => null, 'confirmManual' => null, 'rejectManual' => null],
        ]);
    }
}
