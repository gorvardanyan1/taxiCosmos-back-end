<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionRuleController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('commission-rules');

        return Inertia::render('CommissionRules/Index', [
            'rules' => FixtureTable::paginate($request, $data['rows'], ['zone'], ['vehicle_class'], ['effective_from'], '-effective_from'),
            'filters' => FixtureTable::filters($request, ['vehicle_class']),
            'previewRule' => collect($data['rows'])->firstWhere('id', $data['preview_rule_id']),
            'previewFare' => $data['preview_fare'],
            'actions' => ['createVersion' => null],
        ]);
    }
}
