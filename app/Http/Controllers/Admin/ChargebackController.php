<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChargebackController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $rows = $fixtures->get('chargebacks')['rows'];

        return Inertia::render('Chargebacks/Index', [
            'chargebacks' => FixtureTable::paginate($request, $rows, ['gateway_dispute_id', 'transaction_code', 'rider'], ['status'], ['evidence_due_at'], 'evidence_due_at'),
            'filters' => FixtureTable::filters($request, ['status']),
        ]);
    }
}
