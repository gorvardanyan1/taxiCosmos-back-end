<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RatingController extends Controller
{
    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('ratings');

        return Inertia::render('Ratings/Index', [
            'ratings' => FixtureTable::paginate($request, $data['rows'], ['trip_code', 'comment'], ['direction', 'stars'], ['created_at', 'stars'], '-created_at'),
            'filters' => FixtureTable::filters($request, ['direction', 'stars']),
            'lowRatedDrivers' => $data['low_rated_drivers'],
            'actions' => ['hideComment' => null],
        ]);
    }
}
