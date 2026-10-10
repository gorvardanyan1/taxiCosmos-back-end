<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditTargetType;
use App\Http\Controllers\Controller;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use App\Queries\AuditLogQuery;
use App\Services\Audit\AuditLogger;
use App\Support\AuditEntryPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    private const FILTERS = ['search', 'actor', 'action', 'target_type', 'target_id', 'from', 'to'];

    private const CSV_COLUMNS = ['time_utc', 'actor', 'actor_role', 'action', 'target_type', 'target', 'reason', 'ip', 'user_agent', 'before', 'after'];

    public function index(Request $request): Response
    {
        $entries = AuditLogQuery::for($request, $this->timezone($request))
            ->paginate($this->perPage($request))
            ->appends(Arr::except($request->query(), 'page'))
            ->through(fn (ActivityLogEntry $entry) => AuditEntryPresenter::present($entry));

        $expandedId = (int) $request->query('entry');
        $expanded = $expandedId > 0 ? ActivityLogEntry::query()->find($expandedId) : null;

        return Inertia::render('ActivityLog/Index', [
            'entries' => $entries,
            'filters' => array_filter(Arr::only((array) $request->query('filter', []), self::FILTERS), 'is_string'),
            'sort' => $request->query('sort'),
            'targetTypes' => AuditTargetType::values(),
            'actors' => ActivityLogEntry::query()->whereNotNull('causer_id')->selectRaw("causer_id as id, max(properties->>'actor_name') as name")->groupBy('causer_id')->orderBy('name')->limit(200)->get(),
            'actionNames' => ActivityLogEntry::query()->distinct()->orderBy('description')->limit(300)->pluck('description'),
            'expandedId' => $expanded?->id,
            'expanded' => $expanded === null ? null : AuditEntryPresenter::present($expanded),
            'actions' => ['export' => route('admin.activity-logs.export', Arr::only($request->query(), ['filter', 'sort']), false)],
        ]);
    }

    /**
     * CSV of the current filter (same query as the list, no paging, capped at
     * taxikosmos.audit.export_max_rows). Exporting is itself an audited action.
     */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $query = AuditLogQuery::for($request, $this->timezone($request));
        $max = (int) config('taxikosmos.audit.export_max_rows');
        $filters = array_filter(Arr::only((array) $request->query('filter', []), self::FILTERS), 'is_string');

        $audit->record($request->user(), 'activity_log.exported', context: array_filter(['filters' => $filters, 'max_rows' => $max]));

        return response()->streamDownload(function () use ($query, $max) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::CSV_COLUMNS);
            $written = 0;

            foreach ($query->lazy(500) as $entry) {
                $row = AuditEntryPresenter::present($entry);
                fputcsv($out, array_map($this->safeCell(...), [
                    $entry->created_at->utc()->format('Y-m-d H:i:s'),
                    $row['actor']['name'], $row['actor']['role'], $row['action'], $row['target_type'], $row['target'],
                    $row['reason'], $row['ip'], $row['user_agent'], json_encode($row['before']), json_encode($row['after']),
                ]));

                if (++$written >= $max) {
                    break;
                }
            }

            fclose($out);
        }, 'activity-log-'.now()->utc()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Spreadsheet formula injection: a cell that starts with = + - @ (or a control character) is
     * read as a formula by Excel and Sheets, so it is prefixed with an apostrophe.
     */
    private function safeCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
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
}
