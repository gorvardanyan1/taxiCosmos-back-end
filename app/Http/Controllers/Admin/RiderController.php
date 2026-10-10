<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RiderStatusRequest;
use App\Http\Requests\Admin\UpdateRiderRequest;
use App\Models\User;
use App\Queries\RiderQuery;
use App\Services\Riders\RiderService;
use App\Support\RiderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class RiderController extends Controller
{
    public const TABS = ['trips', 'payments', 'payment-methods', 'tickets', 'ratings', 'activity'];

    private const FILTERS = ['search', 'status', 'registered_from', 'registered_to'];

    public function __construct(private readonly RiderService $riders) {}

    public function index(Request $request): Response
    {
        $riders = RiderQuery::for($request, $this->timezone($request))
            ->paginate($this->perPage($request))
            ->appends(Arr::except($request->query(), 'page'))
            ->through(fn (User $rider) => RiderPresenter::row($rider));

        return Inertia::render('Riders/Index', [
            'riders' => $riders,
            'filters' => array_filter(Arr::only((array) $request->query('filter', []), self::FILTERS), 'is_string'),
            'sort' => is_string($request->query('sort')) ? $request->query('sort') : null,
            'statuses' => array_column(UserStatus::cases(), 'value'),
            'totalRegistered' => User::query()->where('is_rider', true)->count(),
            // Riders sign up in the mobile app, so there is no invite; export arrives with reports (P13-T9).
            'actions' => [
                'export' => null,
                'suspend' => '/admin/riders/{id}/suspend',
                'reactivate' => '/admin/riders/{id}/reactivate',
            ],
        ]);
    }

    public function show(Request $request, int $rider): Response
    {
        $user = User::query()->where('is_rider', true)->findOrFail($rider);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TABS[0];

        return Inertia::render('Riders/Show', [
            'rider' => RiderPresenter::detail($user),
            'tab' => $tab,
            'tabs' => self::TABS,
            'locales' => config('taxikosmos.locales'),
            'actions' => [
                'edit' => route('admin.riders.update', $user->id, absolute: false),
                'suspend' => route('admin.riders.suspend', $user->id, absolute: false),
                'reactivate' => route('admin.riders.reactivate', $user->id, absolute: false),
            ],
        ]);
    }

    public function update(UpdateRiderRequest $request, int $rider): RedirectResponse
    {
        $user = User::query()->where('is_rider', true)->findOrFail($rider);
        $changed = $this->riders->update($this->admin($request), $user, $request->safe()->except('reason'), $request->validated('reason'));

        return back()->with('success', $changed ? 'Rider updated.' : 'Nothing to change.');
    }

    public function suspend(RiderStatusRequest $request, int $rider): RedirectResponse
    {
        $this->riders->suspend($this->admin($request), User::query()->where('is_rider', true)->findOrFail($rider), $request->validated('reason'));

        return back()->with('success', 'Rider suspended.');
    }

    public function reactivate(RiderStatusRequest $request, int $rider): RedirectResponse
    {
        $this->riders->reactivate($this->admin($request), User::query()->where('is_rider', true)->findOrFail($rider), $request->validated('reason'));

        return back()->with('success', 'Rider reactivated.');
    }

    private function timezone(Request $request): string
    {
        $user = $request->user();

        return ($user instanceof User ? $user->timezone : null) ?? config('taxikosmos.display_timezone');
    }

    private function perPage(Request $request): int
    {
        $options = config('taxikosmos.admin.per_page_options');
        $requested = (int) $request->query('per_page');

        return in_array($requested, $options, true) ? $requested : $options[0];
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
