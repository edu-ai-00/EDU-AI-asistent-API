<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unified AUM audit log, read side (BR-BNNASY).
 *
 * Admin-only: the routes sit in the `admin` group. Whether a teacher should
 * see the actions in their own scope is an open product decision; until it is
 * made, nobody but an admin reads this log.
 *
 * Both endpoints take the same filters:
 *   from, to                - inclusive local days, Y-m-d
 *   actor_id, actor_role
 *   action                  - exact key, or a prefix ending in `.*`
 *   target_type, target_id
 *   school_id, classroom_id - scope
 *   source, outcome
 */
class AdminAuditLogController extends Controller
{
    private const FILTERS = [
        'from', 'to', 'actor_id', 'actor_role', 'action', 'target_type', 'target_id',
        'school_id', 'classroom_id', 'source', 'outcome',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/audit-logs — newest first, paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:200',
            'page' => 'sometimes|integer|min:1',
        ]);

        $paginator = $this->filtered($request)
            ->with('actor:id,name,email')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AuditLog $log) => $this->format($log)),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                // What the action filter can offer: every key the log holds.
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            ],
        ]);
    }

    /**
     * GET /api/admin/audit-logs/export?format=csv|json
     *
     * Refused with 422 past config('audit.export_max_rows') so the whole log
     * cannot leave in one click; the admin narrows the filter instead. An
     * export of the audit log is itself an audited action.
     */
    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'format' => 'sometimes|in:csv,json',
        ]);

        $format = $validated['format'] ?? 'csv';
        $query = $this->filtered($request);
        $count = (clone $query)->count();
        $max = (int) config('audit.export_max_rows');

        if ($count > $max) {
            return response()->json([
                'message' => sprintf(
                    'Výběr obsahuje %d záznamů, export jich povoluje nejvýše %d. Zužte prosím filtr.',
                    $count,
                    $max,
                ),
            ], 422);
        }

        // Read before the export logs itself, so the file never contains
        // the row recording its own download.
        $rows = $query->with('actor:id,name,email')->get()->map(fn (AuditLog $log) => $this->format($log));

        $this->audit->log('export.audit_log', meta: [
            'format' => $format,
            'rows' => $rows->count(),
            'filters' => array_filter($request->only(self::FILTERS), fn ($v) => $v !== null && $v !== ''),
        ]);
        $filename = 'audit-log-'.now()->format('Y-m-d').'.'.$format;

        if ($format === 'json') {
            return response()->streamDownload(function () use ($rows) {
                echo json_encode($rows->values(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }, $filename, [
                'Content-Type' => 'application/json; charset=UTF-8',
            ]);
        }

        // Same shape as the activity, ELO and answers exports: tab-separated,
        // BOM-prefixed so Excel opens it as UTF-8.
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID',
                'Čas',
                'ID aktéra',
                'Aktér',
                'Role',
                'Akce',
                'Výsledek',
                'Typ cíle',
                'ID cíle',
                'ID školy',
                'ID třídy',
                'Zdroj',
                'Změny (JSON)',
                'Kontext (JSON)',
                'IP',
                'Zařízení',
            ], "\t");

            foreach ($rows as $r) {
                fputcsv($handle, array_map([$this, 'csvCell'], [
                    $r['id'],
                    $r['created_at'],
                    $r['actor_id'],
                    $r['actor']['email'] ?? $r['actor']['name'] ?? '',
                    $r['actor_role'],
                    $r['action'],
                    $r['outcome'],
                    $r['target_type'],
                    $r['target_id'],
                    $r['scope']['school_id'],
                    $r['scope']['classroom_id'],
                    $r['source'],
                    $r['diff'] === null ? '' : json_encode($r['diff'], JSON_UNESCAPED_UNICODE),
                    $r['meta'] === null ? '' : json_encode($r['meta'], JSON_UNESCAPED_UNICODE),
                    $r['ip'],
                    $r['user_agent'],
                ]), "\t");
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filtered(Request $request): Builder
    {
        $validated = $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:from',
            'actor_id' => 'sometimes|nullable|integer',
            'actor_role' => 'sometimes|nullable|string|max:20',
            'action' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z_]+(\.[a-z_]+)*(\.\*)?$/'],
            'target_type' => 'sometimes|nullable|string|max:40',
            'target_id' => 'sometimes|nullable|integer',
            'school_id' => 'sometimes|nullable|integer',
            'classroom_id' => 'sometimes|nullable|integer',
            'source' => 'sometimes|nullable|in:admin,self_service,system',
            'outcome' => 'sometimes|nullable|in:success,denied',
        ]);

        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        // Days are the admin's local days, like the activity overview.
        $tz = (string) config('work.timezone', 'Europe/Prague');

        if (!empty($validated['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($validated['from'], $tz)->startOfDay()->utc());
        }
        if (!empty($validated['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($validated['to'], $tz)->addDay()->startOfDay()->utc());
        }

        if (!empty($validated['action'])) {
            $action = $validated['action'];

            // The regex above keeps `%` out; `_` can still get through and
            // matches any one character, which at worst lets a group filter
            // take in a near-identical key. Not worth escaping per driver.
            if (str_ends_with($action, '.*')) {
                $query->where('action', 'like', substr($action, 0, -1).'%');
            } else {
                $query->where('action', $action);
            }
        }

        foreach (['actor_id', 'actor_role', 'target_type', 'target_id', 'school_id', 'classroom_id', 'source', 'outcome'] as $column) {
            if (isset($validated[$column]) && $validated[$column] !== '') {
                $query->where($column, $validated[$column]);
            }
        }

        return $query;
    }

    private function format(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'actor_id' => $log->actor_id,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'email' => $log->actor->email,
            ] : null,
            'actor_role' => $log->actor_role,
            'action' => $log->action,
            'outcome' => $log->outcome,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'scope' => [
                'school_id' => $log->school_id,
                'classroom_id' => $log->classroom_id,
            ],
            'source' => $log->source,
            'diff' => $log->diff,
            'meta' => $log->meta,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
        ];
    }

    private function csvCell($value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', (string) $value);
    }
}
