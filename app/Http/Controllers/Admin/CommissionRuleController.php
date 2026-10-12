<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommissionType;
use App\Enums\VehicleClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCommissionRuleRequest;
use App\Models\CommissionRule;
use App\Models\User;
use App\Models\Zone;
use App\Queries\CommissionRuleQuery;
use App\Services\Commission\CommissionRuleLookup;
use App\Services\Commission\CommissionRuleService;
use App\Support\CommissionRulePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class CommissionRuleController extends Controller
{
    private const FILTERS = ['search', 'vehicle_class', 'zone'];

    /** The fare the preview starts with, in whole units of the rule's currency (the template's 12,500). */
    private const PREVIEW_FARE = 12_500;

    public function __construct(private readonly CommissionRuleService $rules, private readonly CommissionRuleLookup $lookup) {}

    public function index(Request $request): Response
    {
        $inForce = $this->lookup->allAt();
        $preview = $inForce->firstWhere('id', (int) $request->query('preview')) ?? $inForce->first();

        return Inertia::render('CommissionRules/Index', [
            'rules' => CommissionRuleQuery::for($request)
                ->paginate($this->perPage($request))
                ->appends(Arr::except($request->query(), 'page'))
                ->through(fn (CommissionRule $rule) => CommissionRulePresenter::row($rule)),
            'filters' => array_filter(Arr::only((array) $request->query('filter', []), self::FILTERS), 'is_string'),
            'sort' => is_string($request->query('sort')) ? $request->query('sort') : null,
            'previewRule' => $preview === null ? null : CommissionRulePresenter::row($preview),
            'previewRules' => $inForce->map(fn (CommissionRule $rule) => ['id' => $rule->id, 'zone' => $rule->zone->name, 'vehicle_class' => $rule->vehicle_class?->value])->values()->all(),
            'previewFare' => ['amount' => self::PREVIEW_FARE * 10 ** $this->decimals($preview?->currency ?? config('taxikosmos.base_currency')), 'currency' => $preview?->currency ?? config('taxikosmos.base_currency')],
            'history' => $this->history($request),
            'zones' => Zone::query()->orderBy('name')->get(['id', 'name', 'currency'])->map(fn (Zone $zone) => ['id' => $zone->id, 'name' => $zone->name, 'currency' => $zone->currency])->all(),
            'vehicleClasses' => array_column(VehicleClass::cases(), 'value'),
            'types' => array_column(CommissionType::cases(), 'value'),
            'actions' => ['createVersion' => route('admin.commission-rules.store', absolute: false)],
        ]);
    }

    /**
     * Creating a zone's first rule and changing its terms are the same call: either adds a version.
     */
    public function store(StoreCommissionRuleRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $saved = $this->rules->saveVersion(
            $admin,
            Zone::query()->findOrFail($request->validated('zone_id')),
            VehicleClass::tryFrom((string) $request->validated('vehicle_class')),
            $request->safe()->only(CommissionRule::TERMS),
        );

        return back()->with('success', $saved['created']
            ? __('Commission rule saved. The new terms apply from now.')
            : __('Nothing changed: these terms are already in force.'));
    }

    /**
     * Every version of the rule behind ?history=<rule id>: the same zone and class.
     *
     * @return array{zone: string, vehicle_class: string|null, versions: list<array<string, mixed>>}|null
     */
    private function history(Request $request): ?array
    {
        $id = filter_var($request->query('history'), FILTER_VALIDATE_INT);
        $rule = $id === false || $id < 1 ? null : CommissionRule::query()->with('zone:id,name')->find($id);

        if ($rule === null) {
            return null;
        }

        return [
            'zone' => $rule->zone->name,
            'vehicle_class' => $rule->vehicle_class?->value,
            'versions' => $this->lookup->history($rule->zone_id, $rule->vehicle_class)->map(fn (CommissionRule $version) => CommissionRulePresenter::row($version))->all(),
        ];
    }

    private function perPage(Request $request): int
    {
        $options = config('taxikosmos.admin.per_page_options');
        $requested = (int) $request->query('per_page');

        return in_array($requested, $options, true) ? $requested : $options[0];
    }

    private function decimals(string $currency): int
    {
        return collect(config('taxikosmos.currencies'))->firstWhere('code', $currency)['decimals'] ?? 2;
    }
}
