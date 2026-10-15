<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSupportTicketRequest;
use App\Http\Requests\Admin\ResolveSupportTicketRequest;
use App\Http\Requests\Admin\SupportTicketMessageRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Queries\SupportTicketQuery;
use App\Services\Support\SupportTicketService;
use App\Support\SupportTicketPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Support Tickets page (permission support.manage on every route): the queue with its filters, the selected conversation,
 * and the staff's actions on it. The logic is SupportTicketService.
 */
class SupportTicketController extends Controller
{
    private const FILTERS = ['search', 'status', 'category', 'priority', 'assignee'];

    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): Response
    {
        $selected = $request->query('ticket');

        return $this->page($request, is_scalar($selected) && ctype_digit((string) $selected) ? SupportTicket::query()->find((int) $selected) : null);
    }

    /** The same page with this ticket open (the link in the safety alert email). */
    public function show(Request $request, int $ticket): Response
    {
        return $this->page($request, SupportTicket::query()->findOrFail($ticket));
    }

    public function assign(AssignSupportTicketRequest $request, int $ticket): RedirectResponse
    {
        $changed = $this->tickets->assign($this->admin($request), SupportTicket::query()->findOrFail($ticket), $request->validated('assignee_id'));

        return back()->with('success', $changed ? __('Ticket assigned.') : __('Nothing to change.'));
    }

    public function message(SupportTicketMessageRequest $request, int $ticket): RedirectResponse
    {
        $this->tickets->staffMessage($this->admin($request), SupportTicket::query()->findOrFail($ticket), $request->validated('body'), $request->internal());

        return back()->with('success', $request->internal() ? __('Internal note added.') : __('Reply sent.'));
    }

    public function resolve(ResolveSupportTicketRequest $request, int $ticket): RedirectResponse
    {
        $this->tickets->resolve($this->admin($request), SupportTicket::query()->findOrFail($ticket), $request->validated('resolution'));

        return back()->with('success', __('Ticket resolved.'));
    }

    public function reopen(Request $request, int $ticket): RedirectResponse
    {
        $this->tickets->reopen($this->admin($request), SupportTicket::query()->findOrFail($ticket));

        return back()->with('success', __('Ticket reopened.'));
    }

    private function page(Request $request, ?SupportTicket $chosen): Response
    {
        $tickets = SupportTicketQuery::for($request)
            ->paginate($this->perPage($request))
            ->appends(Arr::except($request->query(), 'page'))
            ->through(fn (SupportTicket $ticket) => SupportTicketPresenter::row($ticket));

        $selected = $chosen ?? ($tickets->items()[0]['id'] ?? null ? SupportTicket::query()->find($tickets->items()[0]['id']) : null);

        return Inertia::render('SupportTickets/Index', [
            'tickets' => $tickets,
            'filters' => array_filter(Arr::only((array) $request->query('filter', []), self::FILTERS), 'is_string'),
            'selected' => $selected === null ? null : SupportTicketPresenter::detail($selected),
            'statuses' => array_column(SupportStatus::cases(), 'value'),
            'categories' => array_column(SupportCategory::cases(), 'value'),
            'priorities' => array_column(array_reverse(SupportPriority::cases()), 'value'),
            'assignees' => SupportTicketService::staff()->orderBy('name')->get(['id', 'name'])->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'me' => $this->admin($request)->id,
            // Refund (P6-T7) and fare adjustment (P7-T2) are the payments and trips tasks' endpoints: null until they exist.
            'actions' => [
                'assign' => '/admin/support-tickets/{id}/assign',
                'reply' => '/admin/support-tickets/{id}/messages',
                'resolve' => '/admin/support-tickets/{id}/resolve',
                'reopen' => '/admin/support-tickets/{id}/reopen',
                'refund' => null,
                'adjustFare' => null,
            ],
        ]);
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
