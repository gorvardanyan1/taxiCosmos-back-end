<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('activity-logs');
        $entries = FixtureTable::paginate($request, $data['rows'], ['actor.name', 'action', 'target', 'reason'], ['target_type'], ['occurred_at'], '-occurred_at');

        return Inertia::render('ActivityLog/Index', [
            'entries' => $entries,
            'filters' => FixtureTable::filters($request, ['target_type']),
            'targetTypes' => $data['target_types'],
            'expandedId' => collect($entries->items())->firstWhere('id', (int) $request->query('entry'))['id'] ?? null,
            'actions' => ['export' => null],
        ]);
    }
}
