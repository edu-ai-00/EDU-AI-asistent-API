<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchNotificationRequest;
use App\Jobs\SendPushNotification;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\NotificationEvent;
use App\Models\School;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserNotification;
use App\Notifications\NotificationRequest;
use App\Notifications\NotificationResult;
use App\Notifications\NotificationService;
use App\Notifications\NotificationValidationException;
use App\Notifications\RecipientGuard;
use App\Notifications\StatusTracker;
use App\Notifications\TemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * NotificationService::send() — the one pipeline (NOTIF-1, NOTIF-6, spec §2).
 * Every reject / suppress / defer reason, dedup, limits, quiet hours and the
 * queue-outage fallback.
 */
class NotificationServiceTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Delivery basics
    // ─────────────────────────────────────────────────────────────────────────

    public function test_delivers_a_feed_row_with_rendered_texts_and_logs_the_decision(): void
    {
        $student = $this->student();

        $result = $this->sendTo('course_completed', [$student], [
            'course_id' => 5, 'course_name' => 'Zlomky', 'course_code' => 'ABC123',
        ]);

        $this->assertSame(1, $result->deliveredCount());
        $notification = $result->delivered[0];

        $this->assertSame(26, strlen($notification->uuid));
        $this->assertSame('Kurz dokončen', $notification->title);
        $this->assertSame('Gratulujeme! Máš za sebou celý kurz Zlomky.', $notification->body);
        $this->assertSame('/course/ABC123', $notification->deep_link);
        $this->assertSame(['feed'], $notification->channels);
        $this->assertTrue($notification->in_feed);
        $this->assertNull($notification->push_title);
        $this->assertSame(UserNotification::STATUS_CREATED, $notification->fresh()->status);
        $this->assertNull($notification->fresh()->push_status);
        $this->assertSame('pokrok', $notification->category);

        $events = NotificationEvent::where('notification_id', $notification->id)->pluck('event')->all();
        $this->assertSame(['created', 'decision.deliver'], $events);
    }

    public function test_duplicate_recipients_are_merged(): void
    {
        $student = $this->student();

        $result = $this->sendTo('course_completed', [$student->id, $student->id, (string) $student->id], ['course_id' => 1]);

        $this->assertCount(1, $result->decisions);
        $this->assertSame(1, UserNotification::where('user_id', $student->id)->count());
    }

    public function test_empty_recipient_set_is_a_validation_error(): void
    {
        $this->expectException(NotificationValidationException::class);

        $this->sendTo('course_completed', [], ['course_id' => 1]);
    }

    public function test_unknown_type_is_a_validation_error(): void
    {
        try {
            $this->sendTo('no_such_type', [$this->student()]);
            $this->fail('Expected a validation exception');
        } catch (NotificationValidationException $e) {
            $this->assertSame('unknown_type', $e->reason);
            $this->assertArrayHasKey('type_key', $e->errors);
        }
    }

    public function test_invalid_input_is_a_validation_error(): void
    {
        $student = $this->student();

        foreach ([
            'invalid_channels' => ['channels' => ['sms']],
            'invalid_payload' => ['payload' => ['course_name' => ['nested']]],
            'invalid_source' => ['source' => 'cron'],
            'no_channels' => ['channels' => ['push'], 'source' => 'broadcast'],
        ] as $reason => $extra) {
            try {
                $this->sendTo('course_completed', [$student], $extra['payload'] ?? ['course_id' => 1], array_diff_key($extra, ['payload' => 1]));
                $this->fail("Expected {$reason}");
            } catch (NotificationValidationException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $this->assertSame(0, UserNotification::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Recipient checks (step 2) and inactive type (step 3)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_each_recipient_problem_is_a_logged_reject(): void
    {
        $deactivated = $this->student(['deactivated_at' => now()]);
        $target = $this->student();
        $merged = $this->student(['merged_into_user_id' => $target->id]);
        $teacher = $this->teacher();

        $result = $this->sendTo('course_completed', [999999, $deactivated, $merged, $teacher], ['course_id' => 1]);

        $this->assertSame(RecipientGuard::USER_NOT_FOUND, $result->decisionFor(999999)['reason']);
        $this->assertSame(RecipientGuard::USER_DEACTIVATED, $result->decisionFor($deactivated->id)['reason']);
        $this->assertSame(RecipientGuard::USER_MERGED, $result->decisionFor($merged->id)['reason']);
        $this->assertSame(RecipientGuard::ROLE_MISMATCH, $result->decisionFor($teacher->id)['reason']);
        $this->assertSame(4, $result->rejectedCount());
        $this->assertSame(0, UserNotification::count());

        $this->assertDatabaseHas('notification_events', [
            'user_id' => 999999, 'event' => 'decision.reject', 'reason' => 'user_not_found', 'notification_id' => null,
        ]);
    }

    public function test_teacher_role_admits_admins_and_all_admits_everyone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = $this->student();

        $result = $this->sendTo('school_important_message', [$admin, $student], ['title' => 'T', 'body' => 'B']);
        $this->assertSame(2, $result->deliveredCount());

        // A rule narrowing `all` to teachers keeps the admin, drops the student.
        $result = $this->sendTo('maintenance_notice', [$admin, $student], ['title' => 'T', 'body' => 'B'], ['targetRole' => 'teacher']);
        $this->assertSame(NotificationResult::DELIVER, $result->decisionFor($admin->id)['decision']);
        $this->assertSame(RecipientGuard::ROLE_MISMATCH, $result->decisionFor($student->id)['reason']);
    }

    public function test_context_permissions_are_checked(): void
    {
        $school = School::create(['name' => 'ZŠ Test']);
        $classroom = Classroom::create(['name' => '7.A', 'school_id' => $school->id]);
        $otherClassroom = Classroom::create(['name' => '8.B']);
        $course = Course::create([
            'course_id' => 'c-1', 'name' => 'Kurz', 'version' => 1, 'status' => 'published', 'language' => 'cs', 'data' => [],
        ]);

        $member = User::factory()->inClassroom($classroom)->create(['role' => 'student']);
        $outsider = $this->student();
        UserCourse::create(['user_id' => $member->id, 'course_id' => $course->id]);

        $teacher = $this->teacher();
        $classroom->teachers()->attach($teacher->id);
        $schoolTeacher = $this->teacher();
        $school->teachers()->attach($schoolTeacher->id);

        $result = $this->sendTo('course_completed', [$member, $outsider], ['course_id' => 1], ['context' => ['course_id' => $course->id]]);
        $this->assertSame(NotificationResult::DELIVER, $result->decisionFor($member->id)['decision']);
        $this->assertSame(RecipientGuard::NO_COURSE_ACCESS, $result->decisionFor($outsider->id)['reason']);

        $result = $this->sendTo('school_important_message', [$member, $teacher, $outsider], ['title' => 'A', 'body' => 'B'], [
            'context' => ['classroom_id' => $classroom->id],
            'dedupKey' => 'classroom-check',
        ]);
        $this->assertSame(NotificationResult::DELIVER, $result->decisionFor($member->id)['decision']);
        $this->assertSame(NotificationResult::DELIVER, $result->decisionFor($teacher->id)['decision']);
        $this->assertSame(RecipientGuard::NO_CLASSROOM_ACCESS, $result->decisionFor($outsider->id)['reason']);

        $result = $this->sendTo('school_important_message', [$member, $teacher, $schoolTeacher, $outsider], ['title' => 'A', 'body' => 'B'], [
            'context' => ['school_id' => $school->id],
            'dedupKey' => 'school-check',
        ]);
        $this->assertSame(3, $result->deliveredCount());
        $this->assertSame(RecipientGuard::NO_SCHOOL_ACCESS, $result->decisionFor($outsider->id)['reason']);

        $this->assertNotNull($otherClassroom->id);
    }

    public function test_inactive_type_rejects_every_recipient(): void
    {
        $this->type('course_completed')->update(['is_active' => false]);
        $a = $this->student();
        $b = $this->student();

        $result = $this->sendTo('course_completed', [$a, $b], ['course_id' => 1]);

        $this->assertSame(2, $result->rejectedCount());
        $this->assertSame(NotificationService::REASON_TYPE_INACTIVE, $result->decisionFor($a->id)['reason']);
        $this->assertSame(2, NotificationEvent::where('reason', 'type_inactive')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dedup (step 4)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dedup_suppresses_within_the_window_and_is_idempotent(): void
    {
        $student = $this->student();
        $payload = ['course_id' => 7, 'course_name' => 'Kurz'];

        $first = $this->sendTo('course_completed', [$student], $payload);
        $second = $this->sendTo('course_completed', [$student], $payload);
        $third = $this->sendTo('course_completed', [$student], $payload, ['dedupKey' => null]);

        $this->assertSame(1, $first->deliveredCount());
        $this->assertSame(NotificationService::REASON_DUPLICATE, $second->decisionFor($student->id)['reason']);
        $this->assertSame(1, $third->suppressedCount());
        $this->assertSame(1, UserNotification::count());

        // A different payload is a different notification.
        $this->assertSame(1, $this->sendTo('course_completed', [$student], ['course_id' => 8])->deliveredCount());
    }

    public function test_dedup_window_expires(): void
    {
        $student = $this->student();
        $this->type('streak_milestone')->update(['dedup_window_hours' => 24]);

        $this->sendTo('streak_milestone', [$student], ['streak_days' => 7], ['dedupKey' => 'streak:7']);
        $this->travel(25)->hours();

        $result = $this->sendTo('streak_milestone', [$student], ['streak_days' => 7], ['dedupKey' => 'streak:7']);
        $this->assertSame(1, $result->deliveredCount());
    }

    public function test_test_sends_skip_dedup_role_and_limits_and_do_not_count_against_them(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();

        foreach ([1, 2] as $i) {
            $result = $this->sendTo('learning_reminder', [$teacher], [], ['source' => 'test']);
            $this->assertSame(1, $result->deliveredCount(), "test send {$i}");
        }

        $this->assertTrue(UserNotification::where('user_id', $teacher->id)->first()->is_test);

        // A test row neither dedups nor rate-limits the real one.
        $this->sendTo('learning_reminder', [$student], [], ['source' => 'test', 'dedupKey' => 'same']);
        $this->assertSame(1, $this->sendTo('learning_reminder', [$student], [], ['dedupKey' => 'same'])->deliveredCount());

        // But a test still needs an existing, active account.
        $result = $this->sendTo('learning_reminder', [$this->student(['deactivated_at' => now()])], [], ['source' => 'test']);
        $this->assertSame(1, $result->rejectedCount());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Frequency + category caps (step 5)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_frequency_limit_per_type(): void
    {
        $student = $this->student();

        // break_suggestion: 1 per 4 h.
        $this->assertSame(1, $this->sendTo('break_suggestion', [$student], ['minutes' => 45], ['dedupKey' => 'a'])->deliveredCount());

        $second = $this->sendTo('break_suggestion', [$student], ['minutes' => 50], ['dedupKey' => 'b']);
        $this->assertSame(NotificationService::REASON_FREQUENCY_LIMIT, $second->decisionFor($student->id)['reason']);

        $this->travel(5)->hours();
        $this->assertSame(1, $this->sendTo('break_suggestion', [$student], ['minutes' => 60], ['dedupKey' => 'c'])->deliveredCount());
    }

    public function test_category_daily_cap_is_counted_in_the_recipients_local_day(): void
    {
        $student = $this->student();
        $this->preferences($student, ['timezone' => 'America/New_York']);

        // 2026-10-07 23:00 in New York is 03:00 UTC on the 8th.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 03:00:00', 'UTC'));
        $this->assertSame(1, $this->sendTo('activation_daily_quiz', [$student], [], ['dedupKey' => 'q'])->deliveredCount());

        // 23:30 local, same local day: a different aktivace type is capped.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 03:30:00', 'UTC'));
        $capped = $this->sendTo('activation_game', [$student], ['game_name' => 'Sky Jumper'], ['dedupKey' => 'g']);
        $this->assertSame(NotificationService::REASON_CATEGORY_DAILY_CAP, $capped->decisionFor($student->id)['reason']);

        // 00:30 local the next day (04:30 UTC) — a new local day, although the
        // UTC date is the same as the first send.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 04:30:00', 'UTC'));
        $this->assertSame(1, $this->sendTo('activation_game', [$student], ['game_name' => 'Sky Jumper'], ['dedupKey' => 'g2'])->deliveredCount());
    }

    public function test_mandatory_types_bypass_frequency_quiet_hours_and_opt_out_but_not_dedup(): void
    {
        $fake = $this->fakePush();
        $student = $this->student();
        $this->device($student);
        $this->preferences($student, ['push_enabled' => false, 'timezone' => 'Europe/Prague']);
        $this->type('maintenance_notice')->update(['frequency_limit' => 1, 'frequency_period_hours' => 24]);

        // 22:00 Prague — inside the default 20:00–07:00 quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'UTC'));

        $first = $this->sendTo('maintenance_notice', [$student], ['title' => 'Údržba', 'body' => 'Dnes večer'], ['source' => 'broadcast', 'dedupKey' => 'm1']);
        $this->assertSame(UserNotification::PUSH_QUEUED, $first->decisionFor($student->id)['push']['status']);
        $this->assertSame(UserNotification::PUSH_SENT, $first->delivered[0]->fresh()->push_status);
        $this->assertCount(1, $fake->sent);

        // Frequency limit ignored…
        $this->assertSame(1, $this->sendTo('maintenance_notice', [$student], ['title' => 'Jiná', 'body' => 'Zpráva'], ['source' => 'broadcast', 'dedupKey' => 'm2'])->deliveredCount());

        // …dedup is not.
        $dup = $this->sendTo('maintenance_notice', [$student], ['title' => 'Údržba', 'body' => 'Dnes večer'], ['source' => 'broadcast', 'dedupKey' => 'm1']);
        $this->assertSame(NotificationService::REASON_DUPLICATE, $dup->decisionFor($student->id)['reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Channels (step 6)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_requested_channels_are_intersected_with_the_type(): void
    {
        $student = $this->student();

        $result = $this->sendTo('course_completed', [$student], ['course_id' => 1], ['channels' => ['push', 'feed']]);
        $this->assertSame(['feed'], $result->delivered[0]->channels);

        // A rule whose type lost its channel logs no_channels per recipient.
        $suppressed = $this->sendTo('course_completed', [$student], ['course_id' => 2], ['channels' => ['push'], 'source' => 'rule']);
        $this->assertSame(NotificationService::REASON_NO_CHANNELS, $suppressed->decisionFor($student->id)['reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Push sub-decision (step 8)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_push_is_unavailable_without_credentials_and_the_feed_still_works(): void
    {
        $student = $this->student();
        $this->device($student);

        $result = $this->sendTo('learning_reminder', [$student]);

        $notification = $result->delivered[0]->fresh();
        $this->assertTrue($notification->in_feed);
        $this->assertSame(UserNotification::PUSH_SKIPPED, $notification->push_status);
        $this->assertSame(NotificationService::REASON_PUSH_UNAVAILABLE, $notification->failure_reason);
        $this->assertSame(UserNotification::STATUS_CREATED, $notification->status);
        $this->assertDatabaseHas('notification_events', [
            'notification_id' => $notification->id, 'event' => 'decision.suppress', 'reason' => 'push_unavailable',
        ]);
    }

    public function test_push_skipped_without_a_device_or_when_the_user_opted_out(): void
    {
        $this->fakePush();
        $noDevice = $this->student();
        $optedOut = $this->student();
        $this->device($optedOut);
        $this->device($noDevice, ['disabled_at' => now(), 'disabled_reason' => 'dead']);
        $this->preferences($optedOut, ['push_enabled' => false]);

        $result = $this->sendTo('learning_reminder', [$noDevice, $optedOut]);

        $this->assertSame(NotificationService::REASON_NO_DEVICE, $result->decisionFor($noDevice->id)['push']['reason']);
        $this->assertSame(NotificationService::REASON_PUSH_DISABLED, $result->decisionFor($optedOut->id)['push']['reason']);
        $this->assertSame(2, $result->deliveredCount());
    }

    public function test_push_is_queued_and_sent(): void
    {
        $fake = $this->fakePush();
        $student = $this->student(['name' => 'Tereza Nováková']);
        $this->device($student);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));

        $result = $this->sendTo('learning_reminder', [$student]);

        $notification = $result->delivered[0]->fresh();
        $this->assertSame(UserNotification::STATUS_SENT, $notification->status);
        $this->assertSame(UserNotification::PUSH_SENT, $notification->push_status);
        $this->assertNotNull($notification->queued_at);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame('Tereza, stačí pár minut a jsi zase o krok dál.', $notification->push_body);
        $this->assertSame('Tereza, stačí pár minut a jsi zase o krok dál.', $fake->sent[0]['message']->body);
        $this->assertSame($notification->uuid, $fake->sent[0]['message']->data['notification_id']);

        $this->assertSame(
            ['created', 'decision.deliver', 'queued', 'sent'],
            NotificationEvent::where('notification_id', $notification->id)->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_name_is_resolved_per_recipient_with_fallback(): void
    {
        $tereza = $this->student(['name' => 'Tereza Nováková']);
        $nameless = $this->student(['name' => ' ']);

        $result = $this->sendTo('learning_reminder', [$tereza, $nameless]);

        $this->assertSame('Tereza', $result->delivered[0]->payload['name']);
        $this->assertSame('Tereza, stačí pár minut a jsi zase o krok dál.', $result->delivered[0]->push_body);
        $this->assertSame('Ahoj, stačí pár minut a jsi zase o krok dál.', $result->delivered[1]->push_body);
    }

    public function test_quiet_hours_defer_push_across_midnight_in_the_recipients_timezone(): void
    {
        Queue::fake();
        $this->fakePush();
        $student = $this->student();
        $this->device($student);
        $this->preferences($student, ['timezone' => 'Asia/Tokyo']);

        // 14:30 UTC = 23:30 in Tokyo: inside 20:00–07:00, which ends at 07:00
        // Tokyo the next morning = 22:00 UTC today.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 14:30:00', 'UTC'));

        $result = $this->sendTo('learning_reminder', [$student]);

        $notification = $result->delivered[0]->fresh();
        $this->assertSame(UserNotification::PUSH_DEFERRED, $notification->push_status);
        $this->assertSame('2026-10-07 22:00:00', $notification->deferred_until->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(UserNotification::STATUS_CREATED, $notification->status);
        $this->assertSame(1, $result->deferredCount());
        $this->assertDatabaseHas('notification_events', [
            'notification_id' => $notification->id, 'event' => 'decision.defer', 'reason' => 'quiet_hours',
        ]);

        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) use ($notification) {
            return $job->userNotificationId === $notification->id
                && $job->delay instanceof \DateTimeInterface
                && CarbonImmutable::instance($job->delay)->utc()->format('H:i') === '22:00';
        });

        // The same moment is outside quiet hours for a recipient in Prague
        // (16:30 local) — pushed straight away.
        $prague = $this->student();
        $this->device($prague);
        $this->preferences($prague, ['timezone' => 'Europe/Prague']);
        $this->assertSame(UserNotification::PUSH_QUEUED, $this->sendTo('learning_reminder', [$prague])->decisionFor($prague->id)['push']['status']);
    }

    public function test_a_custom_window_that_does_not_cross_midnight(): void
    {
        Queue::fake();
        $this->fakePush();
        $student = $this->student();
        $this->device($student);
        $this->preferences($student, ['timezone' => 'UTC', 'quiet_hours_start' => '12:00:00', 'quiet_hours_end' => '14:00:00']);

        $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00:00', 'UTC'));
        $result = $this->sendTo('learning_reminder', [$student]);

        $this->assertSame('2026-10-07 14:00:00', $result->delivered[0]->fresh()->deferred_until->format('Y-m-d H:i:s'));
    }

    public function test_test_sends_ignore_quiet_hours(): void
    {
        Queue::fake();
        $this->fakePush();
        $student = $this->student();
        $this->device($student);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 22:00:00', 'UTC'));

        $result = $this->sendTo('learning_reminder', [$student], [], ['source' => 'test']);

        $this->assertSame(UserNotification::PUSH_QUEUED, $result->decisionFor($student->id)['push']['status']);
    }

    public function test_a_queue_outage_keeps_the_feed_row_and_parks_the_push(): void
    {
        $fake = new FakePushSender();
        $student = $this->student();
        $this->device($student);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));

        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));

        $service = new NotificationService(
            app(RecipientGuard::class),
            app(TemplateRenderer::class),
            app(StatusTracker::class),
            $fake,
            $bus,
        );

        $result = $service->send(new NotificationRequest('learning_reminder', [$student->id]));

        $notification = $result->delivered[0]->fresh();
        $this->assertTrue($notification->in_feed);
        $this->assertSame(UserNotification::PUSH_DEFERRED, $notification->push_status);
        $this->assertSame(NotificationService::REASON_QUEUE_UNAVAILABLE, $notification->failure_reason);
        $this->assertNotNull($notification->deferred_until);
        $this->assertSame(NotificationService::REASON_QUEUE_UNAVAILABLE, $result->decisionFor($student->id)['push']['reason']);
        $this->assertDatabaseHas('notification_events', [
            'notification_id' => $notification->id, 'event' => 'decision.defer', 'reason' => 'queue_unavailable',
        ]);

        // Once the queue is back, retryPush() re-dispatches it.
        Queue::fake();
        $this->assertTrue($this->service()->retryPush($notification));
        Queue::assertPushed(SendPushNotification::class);
        $this->assertNull($notification->fresh()->failure_reason);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // deliverAt
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_future_deliver_at_schedules_a_delayed_replay(): void
    {
        Queue::fake();
        $student = $this->student();
        $at = now()->addMinutes(30);

        $result = $this->sendTo('course_completed', [$student], ['course_id' => 1], ['deliverAt' => $at]);

        $this->assertTrue($result->scheduled);
        $this->assertSame(0, UserNotification::count());
        Queue::assertPushedOn('notifications', DispatchNotificationRequest::class, function (DispatchNotificationRequest $job) use ($student) {
            return $job->request['recipient_ids'] === [$student->id]
                && $job->request['deliver_at'] === null
                && $job->delay !== null;
        });

        // The replay sends now.
        $job = Queue::pushed(DispatchNotificationRequest::class)->first();
        $job->handle($this->service());
        $this->assertSame(1, UserNotification::count());
    }
}
