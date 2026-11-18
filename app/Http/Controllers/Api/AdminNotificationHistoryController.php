<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationEvent;
use App\Models\UserNotification;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who got what, and what happened to it (NOTIF-8, spec §5.2 "History").
 *
 * The list, the export and the decision log share one set of filters:
 *   from, to       inclusive local days (config('work.timezone')), Y-m-d
 *   user_id, type  (type key), channel (push|feed), source, broadcast_id, is_test (1|0)
 *   result         a status (created … app_opened), `failed` (status or
 *                  push failed), `deferred` (push ever deferred), or
 *                  `suppressed` — which lists the decision log instead: the
 *                  recipients who got nothing (decision.suppress /
 *                  decision.reject without a notification)
 */
class AdminNotificationHistoryController extends Controller
{
    public const RESULTS = [
        'created', 'queued', 'sent', 'delivered', 'viewed', 'clicked', 'app_opened', 'failed',
        'suppressed', 'deferred',
    ];

    private const FILTERS = ['from', 'to', 'user_id', 'type', 'channel', 'result', 'source', 'broadcast_id', 'is_test'];

    private const TIMES = [
        'created_at', 'queued_at', 'sent_at', 'delivered_at', 'viewed_at', 'clicked_at',
        'app_opened_at', 'failed_at', 'target_action_at',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/notification-history
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $perPage = (int) ($filters['per_page'] ?? 50);

        if (($filters['result'] ?? null) === 'suppressed') {
            $paginator = $this->newestFirst($this->decisionQuery($filters, onlyUndelivered: true))
                ->with('user:id,name,email,role')
                ->paginate($perPage);

            return response()->json([
                'data' => collect($paginator->items())->map(fn (NotificationEvent $e) => $this->eventRow($e))->values(),
                'meta' => $this->pageMeta($paginator),
            ]);
        }

        $query = $this->notificationQuery($filters);
        $summary = $this->summary($query);

        $paginator = (clone $query)
            ->with(['user:id,name,email,role', 'type:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (UserNotification $n) => $this->row($n))->values(),
            'meta' => $this->pageMeta($paginator) + ['summary' => $summary],
        ]);
    }

    /**
     * GET /api/admin/notification-history/{id} — one row plus its full
     * events timeline.
     */
    public function show(int $id): JsonResponse
    {
        $notification = UserNotification::with(['user:id,name,email,role', 'type:id,name'])->findOrFail($id);

        $events = NotificationEvent::where('notification_id', $notification->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $this->row($notification) + [
                'body' => $notification->body,
                'push_title' => $notification->push_title,
                'push_body' => $notification->push_body,
                'payload' => $notification->payload,
                'rule_id' => $notification->rule_id,
                'broadcast_id' => $notification->broadcast_id,
                'dedup_key' => $notification->dedup_key,
                'deferred_until' => $notification->deferred_until?->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
                'target_action' => $notification->target_action,
                'events' => $events->map(fn (NotificationEvent $e) => $this->eventRow($e, withUser: false))->values(),
            ],
        ]);
    }

    /**
     * GET /api/admin/notification-history/decisions?user_id=&type=&from=&to=
     * — the per-recipient decision log (deliver / defer / suppress / reject).
     */
    public function decisions(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        $paginator = $this->newestFirst($this->decisionQuery($filters, onlyUndelivered: false))
            ->with('user:id,name,email,role')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (NotificationEvent $e) => $this->eventRow($e))->values(),
            'meta' => $this->pageMeta($paginator),
        ]);
    }

    /**
     * GET /api/admin/notification-history/export — CSV (UTF-8 BOM, `;`),
     * streamed, local times. Refused with 422 above export_max_rows. The
     * export itself is audited.
     */
    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $suppressed = ($filters['result'] ?? null) === 'suppressed';
        $max = (int) config('notifications.export_max_rows', 50000);

        $count = $suppressed
            ? $this->decisionQuery($filters, onlyUndelivered: true)->count()
            : $this->notificationQuery($filters)->count();

        if ($count > $max) {
            return response()->json([
                'message' => sprintf(
                    'Výběr obsahuje %d záznamů, export jich povoluje nejvýše %d. Zužte prosím filtr.',
                    $count,
                    $max,
                ),
            ], 422);
        }

        $this->audit->log('export.notification_history', meta: [
            'rows' => $count,
            'result' => $filters['result'] ?? null,
            'filters' => array_filter($request->only(self::FILTERS), fn ($v) => $v !== null && $v !== ''),
        ]);

        $filename = 'notifikace-historie-' . now()->setTimezone($this->timezone())->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($filters, $suppressed) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            if ($suppressed) {
                $this->writeDecisionCsv($handle, $filters);
            } else {
                $this->writeNotificationCsv($handle, $filters);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Queries
    // ═══════════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:from',
            'user_id' => 'sometimes|nullable|integer',
            'type' => 'sometimes|nullable|string|max:64',
            'channel' => 'sometimes|nullable|in:push,feed',
            'result' => ['sometimes', 'nullable', Rule::in(self::RESULTS)],
            'source' => ['sometimes', 'nullable', Rule::in(UserNotification::SOURCES)],
            'broadcast_id' => 'sometimes|nullable|integer',
            'is_test' => 'sometimes|nullable|boolean',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function notificationQuery(array $filters): Builder
    {
        $query = UserNotification::query();

        $this->applyPeriod($query, $filters, 'user_notifications.created_at');

        if (!empty($filters['user_id'])) {
            $query->where('user_notifications.user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['type'])) {
            $query->where('user_notifications.type_key', $filters['type']);
        }
        if (!empty($filters['source'])) {
            $query->where('user_notifications.source', $filters['source']);
        }
        if (!empty($filters['broadcast_id'])) {
            $query->where('user_notifications.broadcast_id', (int) $filters['broadcast_id']);
        }
        if (isset($filters['is_test']) && $filters['is_test'] !== '') {
            $query->where('user_notifications.is_test', filter_var($filters['is_test'], FILTER_VALIDATE_BOOLEAN));
        }

        if (($filters['channel'] ?? null) === 'feed') {
            $query->where('user_notifications.in_feed', true);
        } elseif (($filters['channel'] ?? null) === 'push') {
            $query->whereJsonContains('user_notifications.channels', 'push');
        }

        switch ($filters['result'] ?? null) {
            case null:
            case '':
                break;
            case 'failed':
                $query->where(function ($q) {
                    $q->where('user_notifications.status', UserNotification::STATUS_FAILED)
                        ->orWhere('user_notifications.push_status', UserNotification::PUSH_FAILED);
                });
                break;
            case 'deferred':
                $query->whereIn('user_notifications.id', NotificationEvent::query()
                    ->select('notification_id')
                    ->where('event', NotificationEvent::DECISION_DEFER)
                    ->whereNotNull('notification_id'));
                break;
            default:
                $query->where('user_notifications.status', $filters['result']);
        }

        return $query;
    }

    /** @param  array<string, mixed>  $filters */
    private function decisionQuery(array $filters, bool $onlyUndelivered): Builder
    {
        $t = 'notification_events.';

        // Unordered: a COUNT(*) with ORDER BY fails on Postgres. Callers order.
        $query = NotificationEvent::query();

        if ($onlyUndelivered) {
            $query->whereIn($t . 'event', [NotificationEvent::DECISION_SUPPRESS, NotificationEvent::DECISION_REJECT])
                ->whereNull($t . 'notification_id');
        } else {
            $query->whereIn($t . 'event', NotificationEvent::DECISIONS);
        }

        $this->applyPeriod($query, $filters, $t . 'created_at');

        if (!empty($filters['user_id'])) {
            $query->where($t . 'user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['type'])) {
            $query->where($t . 'type_key', $filters['type']);
        }
        if (!empty($filters['broadcast_id'])) {
            $query->where($t . 'broadcast_id', (int) $filters['broadcast_id']);
        }
        if (!empty($filters['source'])) {
            $query->where($t . 'meta->source', $filters['source']);
        }
        if (isset($filters['is_test']) && $filters['is_test'] !== '') {
            filter_var($filters['is_test'], FILTER_VALIDATE_BOOLEAN)
                ? $query->where($t . 'meta->source', UserNotification::SOURCE_TEST)
                : $query->where(fn ($q) => $q->whereNull($t . 'meta->source')->orWhere($t . 'meta->source', '!=', UserNotification::SOURCE_TEST));
        }

        return $query;
    }

    private function newestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('notification_events.created_at')->orderByDesc('notification_events.id');
    }

    /** Days are the admin's local days, like the audit log. */
    private function applyPeriod(Builder $query, array $filters, string $column): void
    {
        $tz = $this->timezone();

        if (!empty($filters['from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['from'], $tz)->startOfDay()->utc());
        }
        if (!empty($filters['to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['to'], $tz)->addDay()->startOfDay()->utc());
        }
    }

    /** @return array{total: int, delivered: int, viewed: int, clicked: int, app_opened: int, failed: int} */
    private function summary(Builder $query): array
    {
        $row = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered')
            ->selectRaw('SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) as viewed')
            ->selectRaw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')
            ->selectRaw('SUM(CASE WHEN app_opened_at IS NOT NULL THEN 1 ELSE 0 END) as app_opened')
            ->selectRaw("SUM(CASE WHEN status = 'failed' OR push_status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'delivered' => (int) ($row->delivered ?? 0),
            'viewed' => (int) ($row->viewed ?? 0),
            'clicked' => (int) ($row->clicked ?? 0),
            'app_opened' => (int) ($row->app_opened ?? 0),
            'failed' => (int) ($row->failed ?? 0),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Shapes
    // ═══════════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function row(UserNotification $n): array
    {
        $row = [
            'id' => $n->id,
            'notification_id' => $n->uuid,
            'user' => $n->user ? [
                'id' => $n->user->id,
                'name' => $n->user->name,
                'email' => $n->user->email,
                'role' => $n->user->role,
            ] : null,
            'user_id' => $n->user_id,
            'type_key' => $n->type_key,
            'type_name' => $n->type?->name,
            'category' => $n->category,
            'source' => $n->source,
            'channels' => array_values((array) $n->channels),
            'status' => $n->status,
            'push_status' => $n->push_status,
            'title' => $n->title,
            'deep_link' => $n->deep_link,
            'failure_reason' => $n->failure_reason,
            'is_test' => (bool) $n->is_test,
        ];

        foreach (self::TIMES as $column) {
            $row[$column] = $n->{$column}?->toIso8601String();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function eventRow(NotificationEvent $e, bool $withUser = true): array
    {
        $row = [
            'id' => $e->id,
            'notification_id' => $e->notification_id,
            'user_id' => $e->user_id,
            'type_key' => $e->type_key,
            'rule_id' => $e->rule_id,
            'broadcast_id' => $e->broadcast_id,
            'event' => $e->event,
            'reason' => $e->reason,
            'meta' => $e->meta,
            'created_at' => $e->created_at?->toIso8601String(),
        ];

        if ($withUser) {
            $row['user'] = $e->user ? [
                'id' => $e->user->id,
                'name' => $e->user->name,
                'email' => $e->user->email,
                'role' => $e->user->role,
            ] : null;
        }

        return $row;
    }

    /** @return array{total: int, page: int, per_page: int, last_page: int} */
    private function pageMeta($paginator): array
    {
        return [
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CSV
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $filters
     */
    private function writeNotificationCsv($handle, array $filters): void
    {
        fputcsv($handle, [
            'ID příjemce', 'Jméno', 'E-mail', 'Role', 'Typ', 'Zdroj', 'Kanály', 'Stav', 'Stav push',
            'Vytvořeno', 'Ve frontě', 'Odesláno', 'Doručeno', 'Zobrazeno', 'Kliknuto',
            'Otevřena aplikace', 'Selhalo', 'Cílová akce', 'Odkaz', 'Důvod selhání', 'Test',
        ], ';');

        $rows = $this->notificationQuery($filters)
            ->leftJoin('users', 'users.id', '=', 'user_notifications.user_id')
            ->orderByDesc('user_notifications.created_at')
            ->orderByDesc('user_notifications.id')
            ->select([
                'user_notifications.*',
                'users.name as user_name',
                'users.email as user_email',
                'users.role as user_role',
            ])
            ->cursor();

        foreach ($rows as $n) {
            $cells = [
                $n->user_id,
                $n->getAttribute('user_name'),
                $n->getAttribute('user_email'),
                $n->getAttribute('user_role'),
                $n->type_key,
                $n->source,
                implode(', ', (array) $n->channels),
                $n->status,
                $n->push_status,
            ];

            foreach (self::TIMES as $column) {
                $cells[] = $this->localTime($n->{$column});
            }

            $cells[] = $n->deep_link;
            $cells[] = $n->failure_reason;
            $cells[] = $n->is_test ? 'ano' : 'ne';

            fputcsv($handle, array_map([$this, 'csvCell'], $cells), ';');
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $filters
     */
    private function writeDecisionCsv($handle, array $filters): void
    {
        fputcsv($handle, [
            'Čas', 'ID příjemce', 'Jméno', 'E-mail', 'Role', 'Typ', 'Rozhodnutí', 'Důvod', 'Zdroj',
            'ID pravidla', 'ID rozesílky',
        ], ';');

        $rows = $this->newestFirst($this->decisionQuery($filters, onlyUndelivered: true))
            ->leftJoin('users', 'users.id', '=', 'notification_events.user_id')
            ->select([
                'notification_events.*',
                'users.name as user_name',
                'users.email as user_email',
                'users.role as user_role',
            ])
            ->cursor();

        foreach ($rows as $e) {
            fputcsv($handle, array_map([$this, 'csvCell'], [
                $this->localTime($e->created_at),
                $e->user_id,
                $e->getAttribute('user_name'),
                $e->getAttribute('user_email'),
                $e->getAttribute('user_role'),
                $e->type_key,
                $e->event,
                $e->reason,
                $e->meta['source'] ?? '',
                $e->rule_id,
                $e->broadcast_id,
            ]), ';');
        }
    }

    private function localTime(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return Carbon::instance($value instanceof \DateTimeInterface ? $value : Carbon::parse((string) $value, 'UTC'))
            ->setTimezone($this->timezone())
            ->format('Y-m-d H:i:s');
    }

    private function timezone(): string
    {
        return (string) config('work.timezone', 'Europe/Prague');
    }

    private function csvCell($value): string
    {
        return str_replace(["\r", "\n"], ' ', (string) $value);
    }
}
