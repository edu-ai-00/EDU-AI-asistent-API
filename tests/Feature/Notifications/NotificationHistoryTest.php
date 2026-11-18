<?php

namespace Tests\Feature\Notifications;

use App\Models\AuditLog;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\StatusTracker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin history (NOTIF-8, spec §5.2 "History"): filters in local days,
 * the summary, the decision log behind result=suppressed, the detail
 * timeline and the capped, audited CSV export.
 */
class NotificationHistoryTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private User $admin;
    private User $anna;
    private User $bob;

    private UserNotification $delivered;
    private UserNotification $failed;
    private UserNotification $test;
    private UserNotification $deferred;
    private UserNotification $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->fakePush(false);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->anna = $this->student(['name' => 'Anna Malá', 'email' => 'anna@example.com']);
        $this->bob = $this->student(['name' => 'Bob Velký', 'email' => 'bob@example.com']);
        $tracker = app(StatusTracker::class);

        // 08:00 UTC on the 7th — a delivered feed notification.
        Carbon::setTestNow('2026-10-07 08:00:00');
        $this->delivered = $this->sendTo('course_completed', [$this->anna], ['course_id' => 3, 'course_name' => 'Zlomky'])->delivered[0];
        $tracker->transition($this->delivered, UserNotification::STATUS_DELIVERED);

        // The same again: a duplicate, suppressed — no row, a decision.
        $this->sendTo('course_completed', [$this->anna], ['course_id' => 3, 'course_name' => 'Zlomky']);

        // A broadcast whose push failed.
        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->failed = $this->sendTo('school_important_message', [$this->bob], ['title' => 'Volno', 'body' => 'V pátek volno.'], [
            'source' => 'broadcast', 'broadcastId' => 9,
        ])->delivered[0];
        $this->failed->forceFill(['push_status' => UserNotification::PUSH_FAILED, 'failure_reason' => 'UNAVAILABLE'])->save();
        $tracker->transition($this->failed, UserNotification::STATUS_FAILED, ['reason' => 'UNAVAILABLE']);

        // 22:30 UTC on the 6th is 00:30 on the 7th in Prague — a test send.
        Carbon::setTestNow('2026-10-06 22:30:00');
        $this->test = $this->sendTo('streak_milestone', [$this->anna], ['streak_days' => 7], ['source' => 'test'])->delivered[0];

        // A push deferred by quiet hours.
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->deferred = $this->sendTo('inactivity_comeback', [$this->bob], ['days_inactive' => 4])->delivered[0];
        NotificationEvent::forNotification($this->deferred, NotificationEvent::DECISION_DEFER, 'quiet_hours', ['channel' => 'push']);

        // 22:30 UTC on the 7th is already the 8th in Prague.
        Carbon::setTestNow('2026-10-07 22:30:00');
        $this->tomorrow = $this->sendTo('topic_mastered', [$this->anna], ['topic_key' => 'dim:1'])->delivered[0];

        Carbon::setTestNow('2026-10-08 09:00:00');
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<int> */
    private function ids(string $query = ''): array
    {
        return array_column($this->getJson('/api/admin/notification-history' . $query)->assertOk()->json('data'), 'id');
    }

    public function test_list_rows_and_summary(): void
    {
        $response = $this->getJson('/api/admin/notification-history?per_page=2')->assertOk();

        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame([
            'total' => 5, 'delivered' => 1, 'viewed' => 0, 'clicked' => 0, 'app_opened' => 0, 'failed' => 1,
        ], $response->json('meta.summary'));

        // Newest first.
        $row = $response->json('data.0');
        $this->assertSame($this->tomorrow->id, $row['id']);
        $this->assertSame($this->tomorrow->uuid, $row['notification_id']);
        $this->assertSame(['id' => $this->anna->id, 'name' => 'Anna Malá', 'email' => 'anna@example.com', 'role' => 'student'], $row['user']);
        $this->assertSame('Zvládnutí tématu', $row['type_name']);
        $this->assertSame(['feed'], $row['channels']);
        $this->assertSame('2026-10-07T22:30:00+00:00', $row['created_at']);
        foreach (['queued_at', 'sent_at', 'delivered_at', 'viewed_at', 'clicked_at', 'app_opened_at', 'failed_at', 'target_action_at'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    public function test_filters(): void
    {
        $this->assertSame([$this->tomorrow->id, $this->delivered->id, $this->test->id], $this->ids('?user_id=' . $this->anna->id));
        $this->assertSame([$this->delivered->id], $this->ids('?type=course_completed'));
        $this->assertSame([$this->deferred->id, $this->failed->id], $this->ids('?channel=push'));
        $this->assertSame([$this->failed->id], $this->ids('?source=broadcast'));
        $this->assertSame([$this->failed->id], $this->ids('?broadcast_id=9'));
        $this->assertSame([$this->test->id], $this->ids('?is_test=1'));
        $this->assertCount(4, $this->ids('?is_test=0'));
        $this->assertSame([$this->delivered->id], $this->ids('?result=delivered'));
        $this->assertSame([$this->failed->id], $this->ids('?result=failed'));
        $this->assertSame([$this->deferred->id], $this->ids('?result=deferred'));
    }

    public function test_period_filters_use_local_days(): void
    {
        // The 7th in Prague: 00:30 (22:30 UTC the 6th) … 23:59; not 22:30 UTC on the 7th.
        $this->assertSame(
            [$this->deferred->id, $this->failed->id, $this->delivered->id, $this->test->id],
            $this->ids('?from=2026-10-07&to=2026-10-07'),
        );
        $this->assertSame([$this->tomorrow->id], $this->ids('?from=2026-10-08'));

        $this->getJson('/api/admin/notification-history?from=2026-10-08&to=2026-10-07')
            ->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/admin/notification-history?per_page=500')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_result_suppressed_lists_the_recipients_who_got_nothing(): void
    {
        $response = $this->getJson('/api/admin/notification-history?result=suppressed')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $row = $response->json('data.0');
        $this->assertSame('decision.suppress', $row['event']);
        $this->assertSame('duplicate', $row['reason']);
        $this->assertSame('course_completed', $row['type_key']);
        $this->assertNull($row['notification_id']);
        $this->assertSame($this->anna->id, $row['user']['id']);
    }

    public function test_detail_has_the_events_timeline(): void
    {
        $data = $this->getJson("/api/admin/notification-history/{$this->failed->id}")->assertOk()->json('data');

        $this->assertSame('V pátek volno.', $data['body']);
        $this->assertSame(9, $data['broadcast_id']);
        $this->assertSame(['title' => 'Volno', 'body' => 'V pátek volno.'], $data['payload']);
        $events = array_column($data['events'], 'event');
        $this->assertSame(['created', 'decision.deliver'], array_slice($events, 0, 2));
        $this->assertContains('failed', $events);

        $this->getJson('/api/admin/notification-history/999999')->assertNotFound();
    }

    public function test_decision_log(): void
    {
        $data = $this->getJson('/api/admin/notification-history/decisions?user_id=' . $this->anna->id . '&type=course_completed')
            ->assertOk()->json('data');

        $this->assertSame(['decision.suppress', 'decision.deliver'], array_column($data, 'event'));
    }

    public function test_csv_export_is_bom_semicolon_local_time_and_audited(): void
    {
        $response = $this->get('/api/admin/notification-history/export?user_id=' . $this->anna->id);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('notifikace-historie-2026-10-08.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(4, $lines); // header + 3 rows
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(['ID příjemce', 'Jméno', 'E-mail', 'Role', 'Typ', 'Zdroj', 'Kanály', 'Stav', 'Stav push'], array_slice($header, 0, 9));

        $test = collect(array_slice($lines, 1))->map(fn ($l) => array_combine($header, str_getcsv($l, ';')))->firstWhere('Typ', 'streak_milestone');
        $this->assertSame('Anna Malá', $test['Jméno']);
        $this->assertSame('2026-10-07 00:30:00', $test['Vytvořeno']);
        $this->assertSame('ano', $test['Test']);
        $this->assertSame('test', $test['Zdroj']);

        $log = AuditLog::where('action', 'export.notification_history')->sole();
        $this->assertSame(3, $log->meta['rows']);
        $this->assertSame(['user_id' => (string) $this->anna->id], $log->meta['filters']);
    }

    public function test_export_of_suppressed_decisions(): void
    {
        $csv = $this->get('/api/admin/notification-history/export?result=suppressed')->assertOk()->streamedContent();

        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('duplicate', $lines[1]);
    }

    public function test_export_above_the_limit_is_refused(): void
    {
        config(['notifications.export_max_rows' => 2]);

        $this->getJson('/api/admin/notification-history/export')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Výběr obsahuje 5 záznamů, export jich povoluje nejvýše 2. Zužte prosím filtr.');

        $this->assertFalse(AuditLog::where('action', 'export.notification_history')->exists());
    }
}
