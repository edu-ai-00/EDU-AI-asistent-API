<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsNotificationAdmin;
use App\Http\Controllers\Controller;
use App\Models\NotificationBroadcast;
use App\Models\NotificationType;
use App\Notifications\BroadcastService;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Broadcasts — manual notifications to schools and classes (NOTIF-10,
 * spec §5.2 "Broadcasts", §5.3).
 *
 * Drafts are freely editable; sending freezes the recipients and makes the
 * broadcast read-only (422 on any later edit). Recipient resolution, the
 * send checks and delivery live in App\Notifications\BroadcastService.
 * Audited as notification_broadcast.created / updated / sent / archived /
 * deleted.
 */
class AdminNotificationBroadcastController extends Controller
{
    use FormatsNotificationAdmin;

    /** ?status= values the list understands; `sent` covers everything that left the draft stage. */
    private const STATUS_FILTERS = [
        'draft' => [NotificationBroadcast::STATUS_DRAFT],
        'sent' => [NotificationBroadcast::STATUS_SENDING, NotificationBroadcast::STATUS_SENT, NotificationBroadcast::STATUS_FAILED],
        'sending' => [NotificationBroadcast::STATUS_SENDING],
        'failed' => [NotificationBroadcast::STATUS_FAILED],
        'archived' => [NotificationBroadcast::STATUS_ARCHIVED],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BroadcastService $broadcasts,
    ) {}

    /**
     * GET /api/admin/notification-broadcasts?status=draft|sent|archived
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(array_keys(self::STATUS_FILTERS))],
        ]);

        $query = NotificationBroadcast::query()->orderByDesc('created_at')->orderByDesc('id');

        if (!empty($validated['status'])) {
            $query->whereIn('status', self::STATUS_FILTERS[$validated['status']]);
        }

        $broadcasts = $query->limit(500)->get();
        $delivered = NotificationBroadcast::deliveredCounts($broadcasts);
        $read = NotificationBroadcast::readCounts($broadcasts);

        return response()->json([
            'data' => $broadcasts->map(fn (NotificationBroadcast $b) => $this->broadcastJson($b, $delivered[$b->id] ?? 0, $read[$b->id] ?? 0))->values(),
            'meta' => ['total' => $broadcasts->count()],
        ]);
    }

    /**
     * GET /api/admin/notification-broadcasts/{id} — including the progress
     * counters and read_count (recipients who have seen it).
     */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->broadcastJson(NotificationBroadcast::findOrFail($id))]);
    }

    /**
     * POST /api/admin/notification-broadcasts — a draft.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $actor = $request->user()?->id;

        $broadcast = NotificationBroadcast::create($data + [
            'status' => NotificationBroadcast::STATUS_DRAFT,
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        $this->audit->log(
            'notification_broadcast.created',
            $broadcast,
            diff: AuditLogger::created($broadcast, ['title', 'type_key', 'channels', 'targeting', 'deadline_at']),
        );

        return response()->json([
            'message' => 'Koncept rozesílky uložen.',
            'data' => $this->broadcastJson($broadcast->fresh()),
        ], 201);
    }

    /**
     * PUT /api/admin/notification-broadcasts/{id} — drafts only.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $broadcast = NotificationBroadcast::findOrFail($id);
        $this->ensureDraft($broadcast, 'Odeslanou nebo archivovanou rozesílku nelze upravit.');

        $data = $this->validated($request);
        $broadcast->fill($data + ['updated_by' => $request->user()?->id])->save();

        $diff = AuditLogger::changes($broadcast);
        unset($diff['updated_by']);
        if ($diff !== []) {
            $this->audit->log('notification_broadcast.updated', $broadcast, diff: $diff);
        }

        return response()->json([
            'message' => 'Koncept rozesílky uložen.',
            'data' => $this->broadcastJson($broadcast->fresh()),
        ]);
    }

    /**
     * DELETE /api/admin/notification-broadcasts/{id} — drafts only.
     */
    public function destroy(int $id): JsonResponse
    {
        $broadcast = NotificationBroadcast::findOrFail($id);
        $this->ensureDraft($broadcast, 'Smazat lze jen koncept. Odeslanou rozesílku můžete archivovat.');

        $this->audit->log('notification_broadcast.deleted', $broadcast, meta: [
            'title' => $broadcast->title,
            'type_key' => $broadcast->type_key,
        ]);

        $broadcast->delete();

        return response()->json(['message' => 'Koncept smazán.', 'data' => true]);
    }

    /**
     * POST /api/admin/notification-broadcasts/estimate {targeting} — for
     * unsaved forms too.
     */
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate(BroadcastService::targetingRules());
        $targeting = $this->broadcasts->normalizeTargeting($validated['targeting']);

        return response()->json(['data' => $this->broadcasts->estimate($targeting)]);
    }

    /**
     * POST /api/admin/notification-broadcasts/{id}/preview
     */
    public function preview(int $id): JsonResponse
    {
        $broadcast = NotificationBroadcast::findOrFail($id);

        return response()->json(['data' => $this->broadcasts->preview($broadcast)]);
    }

    /**
     * POST /api/admin/notification-broadcasts/{id}/send {confirm_wide?} → 202.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'confirm_wide' => 'sometimes|boolean',
        ]);

        $broadcast = NotificationBroadcast::findOrFail($id);
        $confirmWide = (bool) ($validated['confirm_wide'] ?? false);

        $sent = $this->broadcasts->send($broadcast, $confirmWide, $request->user()?->id);

        $this->audit->log('notification_broadcast.sent', $sent, meta: [
            'type_key' => $sent->type_key,
            'recipient_count' => $sent->recipient_count,
            'channels' => $sent->channels,
            'wide' => BroadcastService::isWide(
                ['classroom_ids' => (array) ($sent->targeting['classroom_ids'] ?? [])],
                (int) $sent->recipient_count,
            ),
            'confirm_wide' => $confirmWide,
        ]);

        return response()->json([
            'message' => 'Rozesílka se odesílá.',
            'data' => $this->broadcastJson($sent->fresh()),
        ], 202);
    }

    /**
     * POST /api/admin/notification-broadcasts/{id}/archive — draft, sent or
     * failed; not while it is still sending.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $broadcast = NotificationBroadcast::findOrFail($id);

        if ($broadcast->status === NotificationBroadcast::STATUS_SENDING) {
            throw ValidationException::withMessages([
                'status' => ['Rozesílka se ještě odesílá, archivovat ji lze až po dokončení.'],
            ]);
        }

        if ($broadcast->status !== NotificationBroadcast::STATUS_ARCHIVED) {
            $from = $broadcast->status;

            $broadcast->forceFill([
                'status' => NotificationBroadcast::STATUS_ARCHIVED,
                'archived_at' => now(),
                'archived_by' => $request->user()?->id,
            ])->save();

            $this->audit->log('notification_broadcast.archived', $broadcast, diff: [
                'status' => ['from' => $from, 'to' => NotificationBroadcast::STATUS_ARCHIVED],
            ]);
        }

        return response()->json([
            'message' => 'Rozesílka archivována.',
            'data' => $this->broadcastJson($broadcast->fresh()),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @return array<string, mixed>  fillable broadcast attributes
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:' . (int) config('notifications.feed.body_max', 1000),
            'type_key' => 'required|string|max:64',
            'channels' => 'required|array|min:1',
            'channels.*' => ['string', 'distinct', Rule::in(NotificationType::CHANNELS)],
            'deadline_at' => 'sometimes|nullable|date',
        ] + BroadcastService::targetingRules());

        $type = NotificationType::where('key', $validated['type_key'])->first();
        if ($type === null || !$type->manual_allowed) {
            throw ValidationException::withMessages([
                'type_key' => ['Pro ruční rozesílku vyberte typ, který ji povoluje.'],
            ]);
        }

        $channels = array_values(array_intersect(NotificationType::CHANNELS, $validated['channels']));
        $notAllowed = array_values(array_diff($channels, $type->channelList()));
        if ($notAllowed !== []) {
            throw ValidationException::withMessages([
                'channels' => [sprintf('Typ „%s“ nepovoluje kanál %s.', $type->name, implode(', ', $notAllowed))],
            ]);
        }

        return [
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'type_key' => $type->key,
            'channels' => $channels,
            'targeting' => $this->broadcasts->normalizeTargeting($validated['targeting']),
            'deadline_at' => isset($validated['deadline_at']) ? Carbon::parse($validated['deadline_at'])->utc() : null,
        ];
    }

    private function ensureDraft(NotificationBroadcast $broadcast, string $message): void
    {
        if (!$broadcast->isDraft()) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }
}
