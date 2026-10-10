<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('support-tickets');
        $tickets = FixtureTable::paginate($request, $data['rows'], ['code', 'subject', 'requester.name'], ['status', 'priority'], ['created_at'], '-created_at');
        $selected = collect($tickets->items())->firstWhere('id', (int) $request->query('ticket')) ?? $tickets->items()[0] ?? null;

        return Inertia::render('SupportTickets/Index', [
            'tickets' => $tickets,
            'filters' => FixtureTable::filters($request, ['status', 'priority']),
            'selected' => $selected === null ? null : [...$selected, ...$data['conversation']],
            'actions' => ['assign' => null, 'resolve' => null, 'reply' => null, 'refund' => null],
        ]);
    }
}
