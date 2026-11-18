<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsUserRead;
use App\Models\UserNotification;
use App\Notifications\StatusTracker;
use App\Notifications\UnreadCount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The Novinky tab (NOTIF-4 A): published news and the user's in-feed
 * notifications as one list, newest first.
 *
 * GET /api/feed?before=<ISO8601>&before_id=<item id>&limit=20
 *
 * Order is (time desc, kind, id desc), notifications before news at the same
 * second. `before` alone is an exclusive time cursor; with `before_id` (the
 * id of the last item of the previous page, sent back verbatim from
 * meta.next_before_id) items sharing that second are split exactly, so none
 * is skipped or repeated. Each source is read limit + 1 rows deep — enough to
 * fill a merged page and to know whether there is more.
 *
 * Returning a notification here is its delivery: rows not yet delivered move
 * to `delivered` (StatusTracker; feed-only notifications go created →
 * delivered this way).
 *
 * The old /api/news endpoints stay as they are for app versions that
 * predate this one.
 */
class FeedController extends Controller
{
    private const KIND_NOTIFICATION = 'notification';
    private const KIND_NEWS = 'news';

    /** Notifications sort before news at the same timestamp. */
    private const KIND_RANK = [self::KIND_NEWS => 0, self::KIND_NOTIFICATION => 1];

    public function __construct(private readonly StatusTracker $tracker) {}

    public function index(Request $request): JsonResponse
    {
        // A literal "+" in an unencoded query string arrives as a space.
        $before = $request->query('before');
        if (is_string($before) && preg_match('/ \d{2}:?\d{2}$/', $before)) {
            $request->merge(['before' => preg_replace('/ (\d{2}:?\d{2})$/', '+$1', $before)]);
        }

        $validated = $request->validate([
            'before' => 'sometimes|nullable|date',
            'before_id' => 'sometimes|nullable|string|max:64',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        $userId = $request->user()->id;
        $limit = (int) ($validated['limit'] ?? config('notifications.feed.page_size', 20));
        $cursor = $this->cursor($validated['before'] ?? null, $validated['before_id'] ?? null);

        $items = $this->newsItems($userId, $cursor, $limit + 1)
            ->concat($this->notificationItems($userId, $cursor, $limit + 1))
            ->sort(fn (array $a, array $b) => $this->compare($a, $b))
            ->values();

        $hasMore = $items->count() > $limit;
        $page = $items->take($limit)->values();

        foreach ($page as $item) {
            if ($item['model'] instanceof UserNotification && $item['model']->delivered_at === null) {
                $this->tracker->transition($item['model'], UserNotification::STATUS_DELIVERED, ['via' => 'feed']);
            }
        }

        $last = $page->last();

        return response()->json([
            'data' => $page->map(fn (array $item) => $item['data'])->all(),
            'meta' => [
                'unread_count' => UnreadCount::forUser($userId),
                'next_before' => $last ? $last['at']->toIso8601String() : null,
                'next_before_id' => $last ? $last['data']['id'] : null,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * @return array{at: CarbonImmutable, kind: ?string, id: int|string|null}|null
     */
    private function cursor(?string $before, ?string $beforeId): ?array
    {
        if ($before === null || $before === '') {
            return null;
        }

        $cursor = ['at' => CarbonImmutable::parse($before)->utc(), 'kind' => null, 'id' => null];

        if ($beforeId !== null && preg_match('/^news-(\d+)$/', $beforeId, $m)) {
            $cursor['kind'] = self::KIND_NEWS;
            $cursor['id'] = (int) $m[1];
        } elseif ($beforeId !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $beforeId)) {
            $cursor['kind'] = self::KIND_NOTIFICATION;
            $cursor['id'] = strtoupper($beforeId);
        }

        return $cursor;
    }

    /** @return Collection<int, array{at: CarbonImmutable, kind: string, sort_id: int|string, model: mixed, data: array}> */
    private function newsItems(int $userId, ?array $cursor, int $take): Collection
    {
        $query = News::published();

        if ($cursor !== null) {
            $at = $cursor['at'];

            $query->where(function (Builder $q) use ($cursor, $at) {
                $q->where('published_at', '<', $at);

                if ($cursor['kind'] === self::KIND_NOTIFICATION) {
                    // News of the cursor's second rank after every notification of it.
                    $q->orWhere('published_at', '=', $at);
                } elseif ($cursor['kind'] === self::KIND_NEWS) {
                    $q->orWhere(fn (Builder $same) => $same->where('published_at', '=', $at)->where('id', '<', $cursor['id']));
                }
            });
        }

        $news = $query->orderByDesc('published_at')->orderByDesc('id')->limit($take)->get();

        $readIds = array_flip(NewsUserRead::where('user_id', $userId)
            ->whereIn('news_id', $news->pluck('id'))
            ->pluck('news_id')
            ->all());

        return $news->map(function (News $item) use ($readIds) {
            $at = CarbonImmutable::instance($item->published_at)->utc();

            return [
                'at' => $at,
                'kind' => self::KIND_NEWS,
                'sort_id' => (int) $item->id,
                'model' => $item,
                'data' => [
                    'kind' => self::KIND_NEWS,
                    'id' => 'news-' . $item->id,
                    'news_id' => $item->id,
                    'title' => $item->title,
                    'body' => $item->perex,
                    'deep_link' => null,
                    'created_at' => $at->toIso8601String(),
                    'is_read' => isset($readIds[$item->id]),
                ],
            ];
        });
    }

    /** @return Collection<int, array{at: CarbonImmutable, kind: string, sort_id: int|string, model: mixed, data: array}> */
    private function notificationItems(int $userId, ?array $cursor, int $take): Collection
    {
        $query = UserNotification::where('user_id', $userId)->inFeed();

        if ($cursor !== null) {
            $at = $cursor['at'];

            $query->where(function (Builder $q) use ($cursor, $at) {
                $q->where('created_at', '<', $at);

                if ($cursor['kind'] === self::KIND_NOTIFICATION) {
                    $q->orWhere(fn (Builder $same) => $same->where('created_at', '=', $at)->where('uuid', '<', $cursor['id']));
                }
                // Cursor on a news item: notifications of that second ranked
                // before it and were on the previous page already.
            });
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('uuid')
            ->limit($take)
            ->get()
            ->map(function (UserNotification $notification) {
                $at = CarbonImmutable::instance($notification->created_at)->utc();

                return [
                    'at' => $at,
                    'kind' => self::KIND_NOTIFICATION,
                    'sort_id' => (string) $notification->uuid,
                    'model' => $notification,
                    'data' => [
                        'kind' => self::KIND_NOTIFICATION,
                        'id' => $notification->uuid,
                        'notification_id' => $notification->uuid,
                        'type_key' => $notification->type_key,
                        'category' => $notification->category,
                        'title' => $notification->title,
                        'body' => $notification->body,
                        'deep_link' => $notification->deep_link,
                        'created_at' => $at->toIso8601String(),
                        'is_read' => $notification->read_at !== null,
                    ],
                ];
            });
    }

    /** Newest first; notifications before news in the same second; then id desc. */
    private function compare(array $a, array $b): int
    {
        $byTime = $b['at']->getTimestamp() <=> $a['at']->getTimestamp();
        if ($byTime !== 0) {
            return $byTime;
        }

        $byKind = self::KIND_RANK[$b['kind']] <=> self::KIND_RANK[$a['kind']];
        if ($byKind !== 0) {
            return $byKind;
        }

        return is_int($a['sort_id'])
            ? $b['sort_id'] <=> $a['sort_id']
            : strcmp((string) $b['sort_id'], (string) $a['sort_id']);
    }
}
