<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VehicleClass;
use App\Http\Controllers\Controller;
use App\Support\AdminFixtures;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public const METRICS = ['revenue', 'trips', 'driver_earnings', 'commission', 'cancellations', 'refunds', 'payouts', 'driver_debt'];

    public const GROUPS = ['day', 'week', 'month'];

    public function __invoke(Request $request, AdminFixtures $fixtures): Response
    {
        $data = $fixtures->get('reports');
        $currencies = array_column(config('taxikosmos.currencies'), 'code');

        $filters = [
            'metric' => $this->oneOf($request->query('metric'), self::METRICS) ?? 'revenue',
            'from' => $this->date($request->query('from')) ?? '2026-08-01',
            'to' => $this->date($request->query('to')) ?? '2026-09-03',
            'zone' => in_array((int) $request->query('zone'), array_column($data['zones'], 'id'), true) ? (int) $request->query('zone') : null,
            'vehicle_class' => VehicleClass::tryFrom((string) $request->query('vehicle_class'))?->value,
            'group' => $this->oneOf($request->query('group'), self::GROUPS) ?? 'week',
            'currency' => $this->oneOf($request->query('currency'), $currencies) ?? config('taxikosmos.base_currency'),
        ];

        return Inertia::render('Reports/Index', [
            ...$data,
            'metrics' => self::METRICS,
            'groups' => self::GROUPS,
            'filters' => $filters,
            'actions' => ['export' => null, 'generate' => null],
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return Carbon::canBeCreatedFromFormat($value, 'Y-m-d') ? $value : null;
    }
}
