<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DriverBalanceController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('driver-balances');

        return Inertia::render('DriverBalances/Index', [
            'summary' => $data['summary'],
            'balances' => FixtureTable::paginate($request, $data['rows'], ['driver'], [], ['balance.amount', 'debt_limit_used_percent'], 'balance.amount'),
            'filters' => FixtureTable::filters($request),
            'sort' => FixtureTable::sort($request, ['balance.amount', 'debt_limit_used_percent']),
            'actions' => ['recordSettlement' => null],
        ]);
    }
}
