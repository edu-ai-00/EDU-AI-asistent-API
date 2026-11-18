<?php

namespace Tests\Feature\Notifications;

use App\Models\News;
use App\Models\NewsUserRead;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Novinky feed and notification state endpoints (NOTIF-4, NOTIF-8,
 * spec §5.1): merged news + notifications with cursor pagination, unread
 * counts, read marks and the app's events batch.
 */
class FeedTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private User $student;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->student = $this->student();
        [$this->headers] = $this->bearer($this->student);
    }

    private function notificationAt(string $at, array $overrides = [], ?User $user = null): UserNotification
    {
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
        $result = $this->sendTo('course_completed', [$user ?? $this->student], ['course_id' => random_int(1, 1_000_000)] + $overrides);

        return $result->delivered[0];
    }

    private function newsAt(string $at, string $title = 'Novinka'): News
    {
        return News::create(['title' => $title, 'perex' => 'Perex ' . $title, 'body' => 'Text', 'published_at' => CarbonImmutable::parse($at, 'UTC')]);
    }

    public function test_feed_merges_news_and_notifications_newest_first(): void
    {
        $news = $this->newsAt('2026-10-05 08:00:00', 'Starší novinka');
        $older = $this->notificationAt('2026-10-04 09:00:00');
        $newer = $this->notificationAt('2026-10-06 09:00:00', ['course_name' => 'Zlomky', 'course_code' => 'ZL1']);
        News::create(['title' => 'Koncept', 'perex' => 'x', 'body' => 'x', 'published_at' => null]);
        $this->notificationAt('2026-10-06 10:00:00', [], $this->student()); // someone else's

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));
        $response = $this->withHeaders($this->headers)->getJson('/api/feed');

        $response->assertOk();
        $this->assertSame([$newer->uuid, 'news-' . $news->id, $older->uuid], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0', [
            'kind' => 'notification',
            'id' => $newer->uuid,
            'notification_id' => $newer->uuid,
            'type_key' => 'course_completed',
            'category' => 'pokrok',
            'title' => 'Kurz dokončen',
            'body' => 'Gratulujeme! Máš za sebou celý kurz Zlomky.',
            'deep_link' => '/course/ZL1',
            'created_at' => '2026-10-06T09:00:00+00:00',
            'is_read' => false,
        ]);
        $response->assertJsonPath('data.1', [
            'kind' => 'news',
            'id' => 'news-' . $news->id,
            'news_id' => $news->id,
            'title' => 'Starší novinka',
            'body' => 'Perex Starší novinka',
            'deep_link' => null,
            'created_at' => '2026-10-05T08:00:00+00:00',
            'is_read' => false,
        ]);
        $response->assertJsonPath('meta.unread_count', 3);
        $response->assertJsonPath('meta.has_more', false);
    }

    public function test_fetching_marks_notifications_delivered_once(): void
    {
        $notification = $this->notificationAt('2026-10-06 09:00:00');

        $this->withHeaders($this->headers)->getJson('/api/feed')->assertOk();
        $this->withHeaders($this->headers)->getJson('/api/feed')->assertOk();

        $notification->refresh();
        $this->assertSame(UserNotification::STATUS_DELIVERED, $notification->status);
        $this->assertNotNull($notification->delivered_at);
        $this->assertSame(1, NotificationEvent::where('notification_id', $notification->id)->where('event', 'delivered')->count());
    }

    public function test_cursor_pagination_splits_equal_timestamps_without_gaps_or_repeats(): void
    {
        // Five items in the same second, two more earlier.
        $expected = [];
        foreach (range(1, 3) as $i) {
            $expected[] = $this->notificationAt('2026-10-06 09:00:00')->uuid;
        }
        $sameSecondNews = [$this->newsAt('2026-10-06 09:00:00', 'A'), $this->newsAt('2026-10-06 09:00:00', 'B')];
        $earlier = $this->notificationAt('2026-10-05 09:00:00');
        $earliestNews = $this->newsAt('2026-10-04 09:00:00', 'C');

        rsort($expected);
        $expected[] = 'news-' . $sameSecondNews[1]->id;
        $expected[] = 'news-' . $sameSecondNews[0]->id;
        $expected[] = $earlier->uuid;
        $expected[] = 'news-' . $earliestNews->id;

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));
        $seen = [];
        $query = '/api/feed?limit=2';
        $pages = 0;

        do {
            $response = $this->withHeaders($this->headers)->getJson($query)->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $meta = $response->json('meta');
            $query = '/api/feed?limit=2&before=' . urlencode($meta['next_before']) . '&before_id=' . urlencode($meta['next_before_id']);
            $pages++;
        } while ($meta['has_more'] && $pages < 10);

        $this->assertSame($expected, $seen);
        $this->assertSame(4, $pages);
    }

    public function test_before_alone_is_an_exclusive_time_cursor(): void
    {
        $this->notificationAt('2026-10-06 09:00:00');
        $older = $this->notificationAt('2026-10-05 09:00:00');

        $response = $this->withHeaders($this->headers)
            ->getJson('/api/feed?before=2026-10-06T09:00:00+00:00')
            ->assertOk();

        $this->assertSame([$older->uuid], array_column($response->json('data'), 'id'));
    }

    public function test_limit_is_validated(): void
    {
        $this->withHeaders($this->headers)->getJson('/api/feed?limit=51')->assertStatus(422);
        $this->withHeaders($this->headers)->getJson('/api/feed?limit=0')->assertStatus(422);
    }

    public function test_feed_requires_authentication(): void
    {
        $this->getJson('/api/feed')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
    }

    public function test_unread_count_counts_news_and_in_feed_notifications_only(): void
    {
        $this->newsAt('2026-10-01 08:00:00');
        $read = $this->newsAt('2026-10-02 08:00:00');
        NewsUserRead::create(['news_id' => $read->id, 'user_id' => $this->student->id, 'read_at' => now()]);
        $this->notificationAt('2026-10-03 08:00:00');
        // Not in the feed (push-only) — never counted.
        $pushOnly = $this->notificationAt('2026-10-04 08:00:00');
        $pushOnly->update(['in_feed' => false]);

        $this->withHeaders($this->headers)->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 2]]);
    }

    public function test_read_marks_one_and_404s_on_someone_elses(): void
    {
        $mine = $this->notificationAt('2026-10-03 08:00:00');
        $theirs = $this->notificationAt('2026-10-03 08:00:00', [], $this->student());

        $this->withHeaders($this->headers)->postJson("/api/notifications/{$mine->uuid}/read")
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 0]]);
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($mine->fresh()->clicked_at, 'read is not a click');

        $this->withHeaders($this->headers)->postJson("/api/notifications/{$theirs->uuid}/read")->assertNotFound();
        $this->withHeaders($this->headers)->postJson('/api/notifications/nope/read')->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_read_all_marks_notifications_and_published_news(): void
    {
        $news = $this->newsAt('2026-10-01 08:00:00');
        $alreadyRead = $this->newsAt('2026-10-01 09:00:00');
        NewsUserRead::create(['news_id' => $alreadyRead->id, 'user_id' => $this->student->id, 'read_at' => now()]);
        $this->notificationAt('2026-10-03 08:00:00');
        $this->notificationAt('2026-10-03 09:00:00');

        $this->withHeaders($this->headers)->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 0]]);

        $this->assertSame(0, UserNotification::where('user_id', $this->student->id)->whereNull('read_at')->count());
        $this->assertDatabaseHas('news_user_reads', ['news_id' => $news->id, 'user_id' => $this->student->id]);
        $this->assertSame(2, NewsUserRead::where('user_id', $this->student->id)->count());

        // The old news endpoint agrees.
        $this->withHeaders($this->headers)->getJson('/api/news')->assertJsonPath('meta.unread_count', 0);
    }

    public function test_events_batch_is_idempotent_and_skips_foreign_ids(): void
    {
        $mine = $this->notificationAt('2026-10-03 08:00:00');
        $theirs = $this->notificationAt('2026-10-03 08:00:00', [], $this->student());
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));

        $batch = ['events' => [
            ['notification_id' => $mine->uuid, 'event' => 'clicked', 'occurred_at' => '2026-10-03T10:00:00Z'],
            ['notification_id' => $mine->uuid, 'event' => 'viewed', 'occurred_at' => '2026-10-03T09:00:00Z'],
            ['notification_id' => $theirs->uuid, 'event' => 'viewed'],
            ['notification_id' => '01JUNKNOWNUNKNOWNUNKNOWN00', 'event' => 'viewed'],
        ]];

        $this->withHeaders($this->headers)->postJson('/api/notifications/events', $batch)
            ->assertOk()
            ->assertExactJson(['data' => ['accepted' => 2, 'unread_count' => 0]]);

        // Replayed (offline flush retried) — nothing new is logged.
        $this->withHeaders($this->headers)->postJson('/api/notifications/events', $batch)->assertOk();

        $mine->refresh();
        $this->assertSame(UserNotification::STATUS_CLICKED, $mine->status, 'out-of-order viewed does not move status back');
        $this->assertSame('2026-10-03 10:00:00', $mine->clicked_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 09:00:00', $mine->viewed_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($mine->read_at, 'clicked marks read');
        $this->assertSame(1, NotificationEvent::where('notification_id', $mine->id)->where('event', 'clicked')->count());
        $this->assertSame(1, NotificationEvent::where('notification_id', $mine->id)->where('event', 'viewed')->count());
        $this->assertNull($theirs->fresh()->viewed_at);
    }

    public function test_event_times_are_clamped_and_push_delivery_is_recorded(): void
    {
        $notification = $this->notificationAt('2026-10-03 08:00:00');
        $notification->forceFill(['push_status' => UserNotification::PUSH_SENT])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));

        $this->withHeaders($this->headers)->postJson('/api/notifications/events', ['events' => [
            ['notification_id' => $notification->uuid, 'event' => 'delivered', 'occurred_at' => '2020-01-01T00:00:00Z'],
            ['notification_id' => $notification->uuid, 'event' => 'app_opened', 'occurred_at' => '2030-01-01T00:00:00Z'],
        ]])->assertOk();

        $notification->refresh();
        $this->assertSame('2026-10-03 08:00:00', $notification->delivered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 12:00:00', $notification->app_opened_at->format('Y-m-d H:i:s'));
        $this->assertSame(UserNotification::PUSH_DELIVERED, $notification->push_status);
        $this->assertSame(UserNotification::STATUS_APP_OPENED, $notification->status);
    }

    public function test_events_batch_is_validated(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/notifications/events', ['events' => [
            ['notification_id' => 'x', 'event' => 'sent'],
        ]])->assertStatus(422)->assertJsonValidationErrors('events.0.event');

        $tooMany = array_fill(0, 201, ['notification_id' => 'x', 'event' => 'viewed']);
        $this->withHeaders($this->headers)->postJson('/api/notifications/events', ['events' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors('events');
    }
}
