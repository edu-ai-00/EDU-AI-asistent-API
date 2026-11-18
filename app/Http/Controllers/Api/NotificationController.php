<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsUserRead;
use App\Models\UserNotification;
use App\Notifications\StatusTracker;
use App\Notifications\UnreadCount;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's side of notification state (NOTIF-4, NOTIF-8): the badge,
 * read marks, and the delivery/engagement events the app reports.
 *
 * Notifications are addressed by their public notification_id (the ULID in
 * user_notifications.uuid). Someone else's id is a 404 on single-item routes
 * and silently skipped in the events batch.
 */
class NotificationController extends Controller
{
    /** Events the app may report; the rest of the status model is server-side. */
    public const APP_EVENTS = [
        UserNotification::STATUS_DELIVERED,
        UserNotification::STATUS_VIEWED,
        UserNotification::STATUS_CLICKED,
        UserNotification::STATUS_APP_OPENED,
    ];

    public function __construct(private readonly StatusTracker $tracker) {}

    /**
     * GET /api/notifications/unread-count — news + notifications.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread_count' => UnreadCount::forUser($request->user()->id)],
        ]);
    }

    /**
     * POST /api/notifications/{notification_id}/read — marks the feed item
     * read. Not a click: clicked is reported through /events.
     */
    public function read(Request $request, string $notificationId): JsonResponse
    {
        $userId = $request->user()->id;

        $notification = UserNotification::where('user_id', $userId)
            ->where('uuid', strtoupper($notificationId))
            ->firstOrFail();

        UserNotification::whereKey($notification->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => ['unread_count' => UnreadCount::forUser($userId)],
        ]);
    }

    /**
     * POST /api/notifications/read-all — every notification and every
     * published news item of the user, read.
     */
    public function readAll(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = now();

        UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        $unreadNewsIds = News::published()
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->pluck('id');

        if ($unreadNewsIds->isNotEmpty()) {
            // insertOrIgnore: a parallel /api/news/{id}/read must not turn
            // this into a unique-constraint 500.
            NewsUserRead::insertOrIgnore($unreadNewsIds->map(fn ($newsId) => [
                'news_id' => $newsId,
                'user_id' => $userId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        return response()->json([
            'data' => ['unread_count' => UnreadCount::forUser($userId)],
        ]);
    }

    /**
     * POST /api/notifications/events — idempotent batch the app flushes when
     * online: { events: [{notification_id, event, occurred_at}] }.
     *
     * Replays and out-of-order batches are harmless: each state is recorded
     * once and the status only moves forward (StatusTracker). clicked and
     * app_opened also mark the item read. occurred_at is clamped between the
     * notification's creation and now — a device clock is not evidence.
     */
    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => 'required|array|max:200',
            'events.*.notification_id' => 'required|string|max:64',
            'events.*.event' => 'required|string|in:' . implode(',', self::APP_EVENTS),
            'events.*.occurred_at' => 'sometimes|nullable|date',
        ]);

        $userId = $request->user()->id;
        $events = $validated['events'];

        $notifications = UserNotification::where('user_id', $userId)
            ->whereIn('uuid', collect($events)->pluck('notification_id')->map(fn ($id) => strtoupper((string) $id))->unique()->all())
            ->get()
            ->keyBy('uuid');

        $accepted = 0;

        foreach ($events as $event) {
            $notification = $notifications->get(strtoupper((string) $event['notification_id']));
            if ($notification === null) {
                continue;
            }

            $at = $this->clamp($event['occurred_at'] ?? null, $notification);
            $this->tracker->transition($notification, $event['event'], ['via' => 'app'], $at);

            // From the app, `delivered` is a push arriving on the device.
            if ($event['event'] === UserNotification::STATUS_DELIVERED) {
                UserNotification::whereKey($notification->id)
                    ->where('push_status', UserNotification::PUSH_SENT)
                    ->update(['push_status' => UserNotification::PUSH_DELIVERED]);
            }

            $accepted++;
        }

        return response()->json([
            'data' => [
                'accepted' => $accepted,
                'unread_count' => UnreadCount::forUser($userId),
            ],
        ]);
    }

    private function clamp(?string $occurredAt, UserNotification $notification): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if ($occurredAt === null || $occurredAt === '') {
            return $now;
        }

        $at = CarbonImmutable::parse($occurredAt)->utc();
        $created = CarbonImmutable::instance($notification->created_at);

        if ($at->greaterThan($now)) {
            return $now;
        }

        return $at->lessThan($created) ? $created : $at;
    }
}
