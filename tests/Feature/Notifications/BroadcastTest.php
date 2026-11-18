<?php

namespace Tests\Feature\Notifications;

use App\Jobs\ProcessBroadcastChunk;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\NotificationBroadcast;
use App\Models\NotificationBroadcastRecipient;
use App\Models\NotificationRule;
use App\Models\School;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\BroadcastService;
use App\Notifications\StatusTracker;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Broadcasts (NOTIF-10, spec §5.2, §5.3): recipient resolution and
 * estimate, the send checks, the frozen snapshot, chunked delivery through
 * the rule engine with its counters, and the draft-only lifecycle.
 */
class BroadcastTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private User $admin;

    private School $schoolA;
    private School $schoolB;
    private Classroom $a1;
    private Classroom $a2;
    private Classroom $b1;

    /** @var array<string, User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->fakePush(false);

        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);

        $this->schoolA = School::create(['name' => 'ZŠ Komenského']);
        $this->schoolB = School::create(['name' => 'ZŠ Masarykova']);
        $this->a1 = Classroom::create(['name' => '6.A', 'school_id' => $this->schoolA->id]);
        $this->a2 = Classroom::create(['name' => '7.A', 'school_id' => $this->schoolA->id]);
        $this->b1 = Classroom::create(['name' => '6.B', 'school_id' => $this->schoolB->id]);

        $this->u['s1'] = $this->student();
        $this->u['s2'] = $this->student(); // in both A classes
        $this->u['s3'] = $this->student();
        $this->u['s4'] = $this->student(); // school B
        $this->u['s5'] = $this->student(); // deactivated
        $this->u['s6'] = $this->student(); // merged away
        $this->u['s5']->forceFill(['deactivated_at' => now()])->save();
        $this->u['s6']->forceFill(['merged_into_user_id' => $this->u['s1']->id])->save();

        $this->a1->students()->attach([$this->u['s1']->id, $this->u['s2']->id, $this->u['s5']->id]);
        $this->a2->students()->attach([$this->u['s2']->id, $this->u['s3']->id, $this->u['s6']->id]);
        $this->b1->students()->attach([$this->u['s4']->id]);

        $this->u['t1'] = $this->teacher(); // teaches 6.A
        $this->u['t2'] = $this->teacher(); // school A, no class
        $this->u['t3'] = $this->teacher(); // teaches 6.B
        $this->u['t4'] = $this->teacher(); // teaches 6.A and is filed under school A

        $this->a1->teachers()->attach([$this->u['t1']->id, $this->u['t4']->id]);
        $this->b1->teachers()->attach([$this->u['t3']->id]);
        $this->schoolA->teachers()->attach([$this->u['t2']->id, $this->u['t4']->id]);
    }

    /** @param  list<string>  $names */
    private function ids(array $names): array
    {
        $ids = array_map(fn ($n) => $this->u[$n]->id, $names);
        sort($ids);

        return $ids;
    }

    private function targeting(array $schools, array $classes = [], array $roles = ['student', 'teacher']): array
    {
        return [
            'school_ids' => array_map(fn (School $s) => $s->id, $schools),
            'classroom_ids' => array_map(fn (Classroom $c) => $c->id, $classes),
            'roles' => $roles,
        ];
    }

    private function draft(array $overrides = []): NotificationBroadcast
    {
        $response = $this->postJson('/api/admin/notification-broadcasts', array_merge([
            'title' => 'Ředitelské volno',
            'body' => 'V pátek 9. 10. je ředitelské volno.',
            'type_key' => 'school_important_message',
            'channels' => ['push', 'feed'],
            'targeting' => $this->targeting([$this->schoolA], [$this->a1]),
        ], $overrides))->assertCreated();

        return NotificationBroadcast::findOrFail($response->json('data.id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Recipients & estimate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_whole_school_estimate_dedups_and_skips_inactive_accounts(): void
    {
        $data = $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA]),
        ])->assertOk()->json('data');

        // s1, s2 (two classes, once), s3; t1, t2 (school only), t4 (class + school, once).
        $this->assertSame(6, $data['recipient_count']);
        $this->assertSame(['student' => 3, 'teacher' => 3], $data['by_role']);
        $this->assertTrue($data['wide'], 'Whole schools are always wide.');
        $this->assertContains('inactive_skipped', array_column($data['warnings'], 'code'));

        $resolved = app(BroadcastService::class)->resolve(app(BroadcastService::class)->normalizeTargeting($this->targeting([$this->schoolA])));
        $this->assertSame($this->ids(['s1', 's2', 's3', 't1', 't2', 't4']), collect($resolved['recipients'])->keys()->sort()->values()->all());
    }

    public function test_picked_classes_leave_out_school_only_teachers_and_are_not_wide(): void
    {
        $data = $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA], [$this->a1]),
        ])->assertOk()->json('data');

        $this->assertSame(4, $data['recipient_count']); // s1, s2, t1, t4
        $this->assertSame(['student' => 2, 'teacher' => 2], $data['by_role']);
        $this->assertFalse($data['wide']);

        $onlyTeachers = $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA], [$this->a1], ['teacher']),
        ])->json('data');
        $this->assertSame(['student' => 0, 'teacher' => 2], $onlyTeachers['by_role']);
    }

    public function test_wide_threshold_applies_to_picked_classes_too(): void
    {
        config(['notifications.broadcast.wide_threshold' => 4]);

        $data = $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA], [$this->a1]),
        ])->json('data');

        $this->assertTrue($data['wide']);
    }

    public function test_a_class_of_another_school_is_refused(): void
    {
        $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA], [$this->b1]),
        ])->assertStatus(422)->assertJsonValidationErrors(['targeting.classroom_ids']);

        $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => ['school_ids' => [], 'classroom_ids' => [], 'roles' => ['student']],
        ])->assertStatus(422)->assertJsonValidationErrors(['targeting.school_ids']);
    }

    public function test_a_selected_school_without_a_picked_class_is_warned_about(): void
    {
        $data = $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA, $this->schoolB], [$this->a1]),
        ])->json('data');

        $warning = collect($data['warnings'])->firstWhere('code', 'schools_without_classes');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('ZŠ Masarykova', $warning['message']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Drafts
    // ─────────────────────────────────────────────────────────────────────────

    public function test_drafts_are_created_updated_and_deleted_with_audit_rows(): void
    {
        $broadcast = $this->draft();
        $this->assertSame(NotificationBroadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame($this->admin->id, $broadcast->created_by);

        $this->putJson("/api/admin/notification-broadcasts/{$broadcast->id}", [
            'title' => 'Ředitelské volno v pátek',
            'body' => 'V pátek 9. 10. je ředitelské volno.',
            'type_key' => 'school_important_message',
            'channels' => ['feed'],
            'targeting' => $this->targeting([$this->schoolA], [$this->a1]),
            'deadline_at' => '2026-10-09T08:00:00+02:00',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Ředitelské volno v pátek')
            ->assertJsonPath('data.channels', ['feed'])
            ->assertJsonPath('data.deadline_at', '2026-10-09T06:00:00+00:00');

        $this->deleteJson("/api/admin/notification-broadcasts/{$broadcast->id}")->assertOk();
        $this->assertNull(NotificationBroadcast::find($broadcast->id));

        $actions = AuditLog::where('target_type', 'notification_broadcast')->orderBy('id')->pluck('action')->all();
        $this->assertSame([
            'notification_broadcast.created',
            'notification_broadcast.updated',
            'notification_broadcast.deleted',
        ], $actions);
    }

    public function test_only_manual_types_and_their_channels_are_accepted(): void
    {
        $base = [
            'title' => 'X',
            'body' => 'Y',
            'channels' => ['feed'],
            'targeting' => $this->targeting([$this->schoolA], [$this->a1]),
        ];

        $this->postJson('/api/admin/notification-broadcasts', $base + ['type_key' => 'learning_reminder'])
            ->assertStatus(422)->assertJsonValidationErrors(['type_key']);

        $this->postJson('/api/admin/notification-broadcasts', ['channels' => ['sms']] + $base + ['type_key' => 'school_important_message'])
            ->assertStatus(422)->assertJsonValidationErrors(['channels.0']);

        $this->type('maintenance_notice')->update(['channels' => ['feed']]);
        $this->postJson('/api/admin/notification-broadcasts', ['channels' => ['push']] + $base + ['type_key' => 'maintenance_notice'])
            ->assertStatus(422)->assertJsonValidationErrors(['channels']);
    }

    public function test_preview_renders_the_type_and_warns_about_truncation(): void
    {
        $broadcast = $this->draft(['title' => str_repeat('Velmi dlouhý nadpis ', 5)]);

        $data = $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/preview")->assertOk()->json('data');

        $this->assertSame(4, $data['recipient_count']);
        $this->assertFalse($data['wide']);
        $this->assertSame(65, mb_strlen($data['rendered']['push_title']));
        $this->assertStringEndsWith('…', $data['rendered']['push_title']);
        $this->assertSame('V pátek 9. 10. je ředitelské volno.', $data['rendered']['feed_body']);
        $this->assertContains('truncated_push_title', array_column($data['warnings'], 'code'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Send
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sending_delivers_to_the_snapshot_through_the_pipeline(): void
    {
        $broadcast = $this->draft();

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")
            ->assertStatus(202)
            ->assertJsonPath('data.id', $broadcast->id);

        $broadcast->refresh();
        // The sync queue ran every chunk inside the request.
        $this->assertSame(NotificationBroadcast::STATUS_SENT, $broadcast->status);
        $this->assertSame(4, $broadcast->recipient_count);
        $this->assertSame(4, $broadcast->processed_count);
        $this->assertSame(4, $broadcast->delivered_count);
        $this->assertSame(0, $broadcast->failed_count);
        $this->assertNotNull($broadcast->completed_at);
        $this->assertSame($this->admin->id, $broadcast->sent_by);

        $rows = UserNotification::where('broadcast_id', $broadcast->id)->get();
        $this->assertSame($this->ids(['s1', 's2', 't1', 't4']), $rows->pluck('user_id')->sort()->values()->all());
        $this->assertSame(['broadcast'], $rows->pluck('source')->unique()->values()->all());
        $this->assertSame('Ředitelské volno', $rows[0]->title);
        $this->assertSame('V pátek 9. 10. je ředitelské volno.', $rows[0]->body);

        $snapshot = $broadcast->targeting_snapshot;
        $this->assertSame([['id' => $this->a1->id, 'name' => '6.A', 'school_id' => $this->schoolA->id]], $snapshot['classrooms']);
        $this->assertSame('ZŠ Komenského', $snapshot['schools'][0]['name']);
        $this->assertSame(['student' => 2, 'teacher' => 2], $snapshot['by_role']);

        $recipient = NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)->where('user_id', $this->u['s2']->id)->sole();
        $this->assertSame('student', $recipient->role);
        $this->assertSame($this->a1->id, $recipient->classroom_id);
        $this->assertSame($this->schoolA->id, $recipient->school_id);

        $this->assertTrue(AuditLog::where('action', 'notification_broadcast.sent')->where('target_id', $broadcast->id)->exists());
    }

    public function test_feed_only_broadcast_never_pushes(): void
    {
        $broadcast = $this->draft(['channels' => ['feed']]);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        $channels = UserNotification::where('broadcast_id', $broadcast->id)->pluck('channels')->unique()->values()->all();
        $this->assertSame([['feed']], $channels);
    }

    public function test_wide_send_needs_confirmation(): void
    {
        $broadcast = $this->draft(['targeting' => $this->targeting([$this->schoolA])]);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")
            ->assertStatus(422)->assertJsonValidationErrors(['confirm_wide']);
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send", ['confirm_wide' => false])
            ->assertStatus(422);
        $this->assertSame(NotificationBroadcast::STATUS_DRAFT, $broadcast->fresh()->status);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send", ['confirm_wide' => true])->assertStatus(202);
        $this->assertSame(6, $broadcast->fresh()->delivered_count);
    }

    public function test_empty_recipients_are_refused(): void
    {
        $empty = Classroom::create(['name' => '9.C', 'school_id' => $this->schoolA->id]);
        $broadcast = $this->draft(['targeting' => $this->targeting([$this->schoolA], [$empty])]);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")
            ->assertStatus(422)->assertJsonValidationErrors(['targeting']);
        $this->assertSame(NotificationBroadcast::STATUS_DRAFT, $broadcast->fresh()->status);
    }

    public function test_send_needs_a_manual_type_with_an_active_broadcast_rule(): void
    {
        $broadcast = $this->draft();

        NotificationRule::where('key', 'system.school_important_message')->update(['is_active' => false]);
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")
            ->assertStatus(422)->assertJsonValidationErrors(['type_key']);

        NotificationRule::where('key', 'system.school_important_message')->update(['is_active' => true]);
        $this->type('school_important_message')->update(['manual_allowed' => false]);
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")
            ->assertStatus(422)->assertJsonValidationErrors(['type_key']);

        $this->type('school_important_message')->update(['manual_allowed' => true, 'is_active' => false]);
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")
            ->assertStatus(422)->assertJsonValidationErrors(['type_key']);

        $this->assertSame(0, UserNotification::count());
    }

    public function test_a_sent_broadcast_is_immutable(): void
    {
        $broadcast = $this->draft();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        $this->putJson("/api/admin/notification-broadcasts/{$broadcast->id}", [
            'title' => 'Jinak',
            'body' => 'Jinak',
            'type_key' => 'school_important_message',
            'channels' => ['feed'],
            'targeting' => $this->targeting([$this->schoolA], [$this->a1]),
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->deleteJson("/api/admin/notification-broadcasts/{$broadcast->id}")->assertStatus(422);
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(422);

        $this->assertSame('Ředitelské volno', $broadcast->fresh()->title);
        $this->assertSame(4, UserNotification::where('broadcast_id', $broadcast->id)->count());
    }

    public function test_the_snapshot_survives_later_class_changes(): void
    {
        $broadcast = $this->draft();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        $this->a1->students()->detach($this->u['s1']->id);
        $this->a1->update(['name' => '6.A (přejmenovaná)']);

        $broadcast->refresh();
        $this->assertSame(
            $this->ids(['s1', 's2', 't1', 't4']),
            NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)->pluck('user_id')->sort()->values()->all(),
        );
        $this->assertSame('6.A', $broadcast->targeting_snapshot['classrooms'][0]['name']);
        $this->assertSame(4, $broadcast->recipient_count);
    }

    public function test_send_recomputes_recipients_since_the_draft(): void
    {
        $broadcast = $this->draft();
        $this->a1->students()->detach($this->u['s1']->id);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        $this->assertSame(3, $broadcast->fresh()->recipient_count);
        $this->assertFalse(UserNotification::where('user_id', $this->u['s1']->id)->exists());
    }

    public function test_a_queue_outage_keeps_the_broadcast_a_draft(): void
    {
        $broadcast = $this->draft();

        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function dispatch($command) { throw new \RuntimeException('queue down'); }
            public function dispatchSync($command, $handler = null) { throw new \RuntimeException('queue down'); }
            public function dispatchNow($command, $handler = null) { throw new \RuntimeException('queue down'); }
            public function hasCommandHandler($command) { return false; }
            public function getCommandHandler($command) { return false; }
            public function pipeThrough(array $pipes) { return $this; }
            public function map(array $map) { return $this; }
        });

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(503);

        $broadcast->refresh();
        $this->assertSame(NotificationBroadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame(0, NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chunks & counters
    // ─────────────────────────────────────────────────────────────────────────

    public function test_chunks_count_atomically_and_the_last_one_closes_the_broadcast(): void
    {
        config(['notifications.broadcast.chunk' => 2]);
        $broadcast = $this->draft(['targeting' => $this->targeting([$this->schoolA])]);

        Queue::fake();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send", ['confirm_wide' => true])->assertStatus(202);

        $jobs = Queue::pushed(ProcessBroadcastChunk::class)->values();
        $this->assertCount(3, $jobs);
        Queue::assertPushedOn('notifications', ProcessBroadcastChunk::class);
        $this->assertSame(NotificationBroadcast::STATUS_SENDING, $broadcast->fresh()->status);

        $jobs[0]->handle(app(BroadcastService::class));
        $jobs[1]->handle(app(BroadcastService::class));
        // A retried chunk is not counted twice.
        $jobs[1]->handle(app(BroadcastService::class));

        $broadcast->refresh();
        $this->assertSame(4, $broadcast->processed_count);
        $this->assertSame(NotificationBroadcast::STATUS_SENDING, $broadcast->status);

        $jobs[2]->handle(app(BroadcastService::class));

        $broadcast->refresh();
        $this->assertSame(6, $broadcast->processed_count);
        $this->assertSame(6, $broadcast->delivered_count);
        $this->assertSame(NotificationBroadcast::STATUS_SENT, $broadcast->status);
        $this->assertNotNull($broadcast->completed_at);
    }

    public function test_recipients_lost_after_the_snapshot_count_as_failed_and_duplicates_as_suppressed(): void
    {
        $broadcast = $this->draft();

        Queue::fake();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);
        $job = Queue::pushed(ProcessBroadcastChunk::class)->first();

        // Deactivated between the snapshot and delivery → rejected.
        $this->u['s1']->forceFill(['deactivated_at' => now()])->save();
        // Already holds this broadcast's notification key → duplicate.
        $this->sendTo('school_important_message', [$this->u['t1']], ['title' => 'x', 'body' => 'y'], [
            'dedupKey' => 'school_important_message:broadcast:' . $broadcast->id,
        ]);

        $job->handle(app(BroadcastService::class));

        $broadcast->refresh();
        $this->assertSame(4, $broadcast->processed_count);
        $this->assertSame(2, $broadcast->delivered_count);
        $this->assertSame(1, $broadcast->suppressed_count);
        $this->assertSame(1, $broadcast->failed_count);
        $this->assertSame(NotificationBroadcast::STATUS_SENT, $broadcast->status);
    }

    public function test_delivered_counts_only_recipients_whose_device_got_the_notification(): void
    {
        $broadcast = $this->draft();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        // Handed to the pipeline is processed, not delivered (BR-PJEVXV).
        $this->getJson("/api/admin/notification-broadcasts/{$broadcast->id}")
            ->assertOk()
            ->assertJsonPath('data.processed_count', 4)
            ->assertJsonPath('data.delivered_count', 0);

        // s1 signs in and the app syncs the feed.
        Sanctum::actingAs($this->u['s1']);
        $this->getJson('/api/feed')->assertOk();

        // t1's device reports the push arriving.
        $uuid = UserNotification::where('broadcast_id', $broadcast->id)->where('user_id', $this->u['t1']->id)->value('uuid');
        Sanctum::actingAs($this->u['t1']);
        $this->postJson('/api/notifications/events', ['events' => [
            ['notification_id' => $uuid, 'event' => 'delivered'],
        ]])->assertOk()->assertJsonPath('data.accepted', 1);

        // s2 only got the later deadline reminder — not this broadcast's message.
        $reminder = $this->sendTo('org_deadline_reminder', [$this->u['s2']], ['title' => 'Ředitelské volno', 'deadline' => '9. 10. 2026 08:00'], [
            'source' => UserNotification::SOURCE_BROADCAST,
            'broadcastId' => $broadcast->id,
        ])->delivered[0];
        Sanctum::actingAs($this->u['s2']);
        $this->postJson('/api/notifications/events', ['events' => [
            ['notification_id' => $reminder->uuid, 'event' => 'delivered'],
        ]])->assertOk()->assertJsonPath('data.accepted', 1);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/notification-broadcasts/{$broadcast->id}")
            ->assertOk()
            ->assertJsonPath('data.processed_count', 4)
            ->assertJsonPath('data.delivered_count', 2);

        $listed = collect($this->getJson('/api/admin/notification-broadcasts?status=sent')->assertOk()->json('data'))
            ->firstWhere('id', $broadcast->id);
        $this->assertSame(2, $listed['delivered_count']);
    }

    public function test_a_chunk_that_gives_up_counts_as_failed_and_fails_an_all_failed_broadcast(): void
    {
        $broadcast = $this->draft();

        Queue::fake();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);
        $job = Queue::pushed(ProcessBroadcastChunk::class)->first();

        $job->failed(new \RuntimeException('database gone'));
        $job->failed(new \RuntimeException('again'));

        $broadcast->refresh();
        $this->assertSame(4, $broadcast->processed_count);
        $this->assertSame(4, $broadcast->failed_count);
        $this->assertSame(NotificationBroadcast::STATUS_FAILED, $broadcast->status);
    }

    public function test_read_count_is_the_recipients_who_saw_it(): void
    {
        $broadcast = $this->draft();
        $this->getJson("/api/admin/notification-broadcasts/{$broadcast->id}")->assertJsonPath('data.read_count', 0);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        $tracker = app(StatusTracker::class);
        $mine = fn (string $who) => UserNotification::where('broadcast_id', $broadcast->id)
            ->where('type_key', $broadcast->type_key)
            ->where('user_id', $this->u[$who]->id)
            ->sole();

        // Seen in the feed, then opened — one reader, not two.
        $tracker->transition($mine('s1'), UserNotification::STATUS_VIEWED);
        $tracker->transition($mine('s1'), UserNotification::STATUS_CLICKED);
        // Opened straight from the push; the feed row was never on screen.
        $tracker->transition($mine('s2'), UserNotification::STATUS_APP_OPENED);
        // „Označit vše jako přečtené“ without ever seeing it is not reading it.
        $mine('t1')->forceFill(['read_at' => now()])->save();
        // t4: the message delivered only; seeing the later deadline reminder
        // (same broadcast_id, its own type) is not reading this message.
        $tracker->transition($mine('t4'), UserNotification::STATUS_DELIVERED);
        $reminder = $this->sendTo('org_deadline_reminder', [$this->u['t4']], ['title' => 'Ředitelské volno', 'deadline' => '9. 10. 2026 08:00'], [
            'source' => UserNotification::SOURCE_BROADCAST,
            'broadcastId' => $broadcast->id,
        ])->delivered[0];
        $tracker->transition($reminder, UserNotification::STATUS_VIEWED);

        $this->getJson("/api/admin/notification-broadcasts/{$broadcast->id}")
            ->assertOk()
            ->assertJsonPath('data.read_count', 2);

        $list = $this->getJson('/api/admin/notification-broadcasts?status=sent')->assertOk()->json('data');
        $this->assertSame([2], array_column($list, 'read_count'));

        // Responses the admin writes straight into its cache carry it too.
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.read_count', 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Archive, list, access
    // ─────────────────────────────────────────────────────────────────────────

    public function test_archive_and_status_filters(): void
    {
        $sent = $this->draft();
        $this->postJson("/api/admin/notification-broadcasts/{$sent->id}/send")->assertStatus(202);
        $draft = $this->draft(['title' => 'Koncept']);

        $this->assertSame([$draft->id], array_column($this->getJson('/api/admin/notification-broadcasts?status=draft')->json('data'), 'id'));
        $this->assertSame([$sent->id], array_column($this->getJson('/api/admin/notification-broadcasts?status=sent')->json('data'), 'id'));

        $this->postJson("/api/admin/notification-broadcasts/{$sent->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
        $this->assertSame($this->admin->id, $sent->fresh()->archived_by);
        $this->assertSame([$sent->id], array_column($this->getJson('/api/admin/notification-broadcasts?status=archived')->json('data'), 'id'));
        $this->assertTrue(AuditLog::where('action', 'notification_broadcast.archived')->where('target_id', $sent->id)->exists());

        $this->getJson("/api/admin/notification-broadcasts/{$sent->id}")
            ->assertOk()
            ->assertJsonPath('data.processed_count', 4)
            ->assertJsonPath('data.type_name', 'Důležitá zpráva školy nebo učitele');
    }

    public function test_a_sending_broadcast_cannot_be_archived(): void
    {
        $broadcast = $this->draft();
        Queue::fake();
        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/send")->assertStatus(202);

        $this->postJson("/api/admin/notification-broadcasts/{$broadcast->id}/archive")->assertStatus(422);
    }

    public function test_teachers_cannot_use_broadcasts(): void
    {
        Sanctum::actingAs($this->u['t1']);

        $this->getJson('/api/admin/notification-broadcasts')->assertForbidden();
        $this->postJson('/api/admin/notification-broadcasts/estimate', [
            'targeting' => $this->targeting([$this->schoolA]),
        ])->assertForbidden();
    }
}
