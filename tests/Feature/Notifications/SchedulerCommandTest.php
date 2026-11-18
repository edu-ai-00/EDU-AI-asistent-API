<?php

namespace Tests\Feature\Notifications;

use App\Jobs\EvaluateNotificationEvent;
use App\Jobs\SendPushNotification;
use App\Models\Course;
use App\Models\MiniGame;
use App\Models\MiniGamePlay;
use App\Models\NotificationBroadcast;
use App\Models\NotificationBroadcastRecipient;
use App\Models\PracticeCard;
use App\Models\PracticeReviewLog;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserNotification;
use App\Models\WorkHeartbeat;
use App\Notifications\NotificationService;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * The scheduled notification commands (spec §3.5, §6.2): who they pick,
 * in whose local time, and that running them twice is harmless.
 */
class SchedulerCommandTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return Collection<int, EvaluateNotificationEvent> */
    private function emitted(string $event): Collection
    {
        return Queue::pushed(EvaluateNotificationEvent::class, fn (EvaluateNotificationEvent $job) => $job->eventKey === $event)->values();
    }

    /** @return list<int> */
    private function recipients(string $event): array
    {
        $ids = $this->emitted($event)->flatMap(fn (EvaluateNotificationEvent $job) => $job->userIds)->all();
        sort($ids);

        return $ids;
    }

    private function usedAppAt(User $user, string $at): void
    {
        $token = $user->createToken('app')->accessToken;
        $token->forceFill(['last_used_at' => Carbon::parse($at)])->save();
    }

    private function heartbeat(User $user, string $at): void
    {
        WorkHeartbeat::create([
            'user_id' => $user->id,
            'client_uuid' => (string) Str::uuid(),
            'course_id' => 'ZLOMKY',
            'occurred_at' => Carbon::parse($at),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // notifications:learning-reminders
    // ═════════════════════════════════════════════════════════════════════════

    public function test_reminder_fires_for_a_reminder_time_in_the_last_five_local_minutes(): void
    {
        Queue::fake();
        // 15:02 UTC = 17:02 in Prague (CEST).
        Carbon::setTestNow('2026-10-07 15:02:00');

        $due = $this->student();
        $this->preferences($due, ['reminder_time' => '17:00']);
        $early = $this->student();
        $this->preferences($early, ['reminder_time' => '17:03']);
        $late = $this->student();
        $this->preferences($late, ['reminder_time' => '16:57']);
        $off = $this->student();
        $this->preferences($off, ['reminder_time' => null]);

        $this->artisan('notifications:learning-reminders')->assertSuccessful();

        $jobs = $this->emitted('habit.reminder_due');
        $this->assertCount(1, $jobs);
        $this->assertSame([$due->id], $jobs[0]->userIds);
        $this->assertSame(['local_time' => '17:00'], $jobs[0]->fields);
        $this->assertSame('learning_reminder:2026-10-07', $jobs[0]->dedupKey);
    }

    public function test_reminder_skips_students_who_learned_today_but_not_yesterday(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 15:00:00');

        $learnedToday = $this->student();
        $this->preferences($learnedToday, ['reminder_time' => '17:00']);
        // 06:30 UTC = 08:30 Prague today.
        $this->heartbeat($learnedToday, '2026-10-07 06:30:00');

        $learnedYesterday = $this->student();
        $this->preferences($learnedYesterday, ['reminder_time' => '17:00']);
        // 21:30 UTC yesterday = 23:30 Prague yesterday.
        $this->heartbeat($learnedYesterday, '2026-10-06 21:30:00');

        $teacher = $this->teacher();
        $this->preferences($teacher, ['reminder_time' => '17:00']);
        $deactivated = $this->student();
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        $this->preferences($deactivated, ['reminder_time' => '17:00']);

        $this->artisan('notifications:learning-reminders')->assertSuccessful();

        $this->assertSame([$learnedYesterday->id], $this->recipients('habit.reminder_due'));
    }

    public function test_reminder_uses_the_students_own_timezone_and_local_date(): void
    {
        Queue::fake();
        // 01:02 UTC on the 8th = 21:02 on the 7th in New York (EDT).
        Carbon::setTestNow('2026-10-08 01:02:00');

        $newYork = $this->student();
        $this->preferences($newYork, ['reminder_time' => '21:00', 'timezone' => 'America/New_York']);
        $prague = $this->student();
        $this->preferences($prague, ['reminder_time' => '21:00']);

        $this->artisan('notifications:learning-reminders')->assertSuccessful();

        $jobs = $this->emitted('habit.reminder_due');
        $this->assertCount(1, $jobs);
        $this->assertSame([$newYork->id], $jobs[0]->userIds);
        $this->assertSame('learning_reminder:2026-10-07', $jobs[0]->dedupKey);
    }

    public function test_a_reminder_just_before_midnight_belongs_to_its_own_day(): void
    {
        Queue::fake();
        // 22:00 UTC = 00:00 Prague on the 8th; the 23:58 reminder is the 7th's.
        Carbon::setTestNow('2026-10-07 22:00:00');

        $student = $this->student();
        $this->preferences($student, ['reminder_time' => '23:58']);
        // Learned at 21:00 Prague on the 7th.
        $this->heartbeat($student, '2026-10-07 19:00:00');

        $this->artisan('notifications:learning-reminders')->assertSuccessful();

        $this->assertCount(0, $this->emitted('habit.reminder_due'));
    }

    public function test_running_reminders_twice_creates_one_notification(): void
    {
        $this->fakePush(false);
        Carbon::setTestNow('2026-10-07 15:01:00');

        $student = $this->student();
        $this->preferences($student, ['reminder_time' => '17:00']);

        $this->artisan('notifications:learning-reminders')->assertSuccessful();
        $this->artisan('notifications:learning-reminders')->assertSuccessful();

        $this->assertSame(1, UserNotification::where('user_id', $student->id)->where('type_key', 'learning_reminder')->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // notifications:activation
    // ═════════════════════════════════════════════════════════════════════════

    private function course(): Course
    {
        return Course::create([
            'course_id' => 'C_' . Str::random(6),
            'name' => 'Zlomky',
            'status' => 'published',
            'version' => 1,
            'language' => 'cs',
            'data' => ['lessons' => []],
        ]);
    }

    private function enrol(User $user): void
    {
        UserCourse::create([
            'user_id' => $user->id,
            'course_id' => $this->course()->id,
            'status' => 'in_progress',
            'progress_percent' => 10,
            'completed_lessons' => 0,
            'current_lesson_index' => 0,
            'time_spent_seconds' => 0,
        ]);
    }

    private function dueCards(User $user, int $count): PracticeCard
    {
        $card = null;
        for ($i = 0; $i < $count; $i++) {
            $card = PracticeCard::create([
                'user_id' => $user->id,
                'course_id' => 'ZLOMKY',
                'lesson_id' => 'L1',
                'block_id' => 'B' . Str::random(6),
                'source_type' => 'quiz',
                'due_date' => now()->subHour(),
            ]);
        }

        return $card;
    }

    private function reviewed(User $user, PracticeCard $card, string $at): void
    {
        PracticeReviewLog::create([
            'card_id' => $card->id,
            'user_id' => $user->id,
            'rating' => 3,
            'shown_at' => Carbon::parse($at)->subMinute(),
            'reviewed_at' => Carbon::parse($at),
            'response_time_sec' => 10,
            'repetition_number' => 1,
            'stability_after' => 1.0,
            'difficulty_after' => 5.0,
            'next_due_date' => Carbon::parse($at)->addDay(),
            'interval_days' => 1,
        ]);
    }

    private function quizAt(User $user, string $at): void
    {
        $attempt = QuizAttempt::create([
            'user_id' => $user->id,
            'course_id' => 'ZLOMKY',
            'total_questions' => 5,
            'correct_answers' => 4,
            'score_percent' => 80,
            'started_at' => Carbon::parse($at),
        ]);
        $attempt->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    public function test_activation_picks_the_candidate_with_the_oldest_own_activity(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 13:30:00'); // 15:30 Prague
        $game = MiniGame::factory()->create(['title' => 'Kulička', 'sort_order' => 1]);

        // practice reviewed yesterday, quiz 5 days ago, game never → game.
        $student = $this->student();
        $this->usedAppAt($student, '2026-10-06 18:00:00');
        $this->enrol($student);
        $card = $this->dueCards($student, 2);
        $this->reviewed($student, $card, '2026-10-06 18:00:00');
        $this->quizAt($student, '2026-10-02 10:00:00');

        $this->artisan('notifications:activation')->assertSuccessful();

        $jobs = $this->emitted('activation.daily');
        $this->assertCount(1, $jobs);
        $this->assertSame([
            'suggestion' => 'game',
            'due_count' => 2,
            'game_name' => 'Kulička',
            'days_since_activity' => 1,
        ], $jobs[0]->fields);
        $this->assertSame('activation:game:2026-10-07', $jobs[0]->dedupKey);
        $this->assertSame($game->title, $jobs[0]->fields['game_name']);
    }

    public function test_activation_picks_practice_when_it_is_the_oldest_and_quiz_on_a_tie(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 13:30:00');

        // Practice reviewed 6 days ago, quiz 2 days ago → practice.
        $practice = $this->student();
        $this->usedAppAt($practice, '2026-10-05 08:00:00');
        $this->enrol($practice);
        $card = $this->dueCards($practice, 3);
        $this->reviewed($practice, $card, '2026-10-01 08:00:00');
        $this->quizAt($practice, '2026-10-05 08:00:00');

        // Never did either → tie → quiz (catalog order).
        $tie = $this->student();
        $this->usedAppAt($tie, '2026-10-05 08:00:00');
        $this->enrol($tie);
        $this->dueCards($tie, 1);

        $this->artisan('notifications:activation')->assertSuccessful();

        $bySuggestion = $this->emitted('activation.daily')->keyBy(fn ($job) => $job->fields['suggestion']);
        $this->assertSame([$practice->id], $bySuggestion['practice']->userIds);
        $this->assertSame(3, $bySuggestion['practice']->fields['due_count']);
        $this->assertSame([$tie->id], $bySuggestion['quiz']->userIds);
    }

    public function test_activation_skips_active_today_long_gone_and_no_candidate(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 13:30:00');

        $activeToday = $this->student();
        $this->usedAppAt($activeToday, '2026-10-07 07:00:00');
        $this->enrol($activeToday);
        $this->heartbeat($activeToday, '2026-10-07 07:00:00');

        $longGone = $this->student();
        $this->usedAppAt($longGone, '2026-09-20 07:00:00');
        $this->enrol($longGone);

        $nothingToSuggest = $this->student();
        $this->usedAppAt($nothingToSuggest, '2026-10-06 07:00:00');

        $teacher = $this->teacher();
        $this->usedAppAt($teacher, '2026-10-06 07:00:00');
        $this->enrol($teacher);

        $this->artisan('notifications:activation')->assertSuccessful();

        $this->assertSame([], $this->recipients('activation.daily'));
    }

    public function test_activation_judges_today_in_the_students_timezone(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 13:30:00');

        // A heartbeat at 16:00 UTC on the 6th: the 7th already in Tokyo
        // (01:00), still the 6th in Prague (18:00).
        $tokyo = $this->student();
        $this->preferences($tokyo, ['timezone' => 'Asia/Tokyo']);
        $prague = $this->student();
        foreach ([$tokyo, $prague] as $student) {
            $this->usedAppAt($student, '2026-10-06 16:00:00');
            $this->enrol($student);
            $this->heartbeat($student, '2026-10-06 16:00:00');
        }

        $this->artisan('notifications:activation')->assertSuccessful();

        $this->assertSame([$prague->id], $this->recipients('activation.daily'));
    }

    public function test_running_activation_twice_sends_one_nudge(): void
    {
        $this->fakePush(false);
        Carbon::setTestNow('2026-10-07 13:30:00');

        $student = $this->student();
        $this->usedAppAt($student, '2026-10-06 07:00:00');
        $this->enrol($student);

        $this->artisan('notifications:activation')->assertSuccessful();
        $this->artisan('notifications:activation')->assertSuccessful();

        $this->assertSame(1, UserNotification::where('user_id', $student->id)->where('category', 'aktivace')->count());
        $this->assertSame('activation_daily_quiz', UserNotification::where('user_id', $student->id)->value('type_key'));
    }

    public function test_a_game_out_of_attempts_is_not_suggested(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 13:30:00');
        $game = MiniGame::factory()->create(['daily_attempts' => 1]);

        $student = $this->student();
        $this->usedAppAt($student, '2026-10-06 07:00:00');
        MiniGamePlay::create(['mini_game_id' => $game->id, 'user_id' => $student->id, 'played_at' => now()->subHours(3)]);

        $this->artisan('notifications:activation')->assertSuccessful();

        // No course, no cards, the one game spent: no candidate at all.
        $this->assertSame([], $this->recipients('activation.daily'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // notifications:inactivity
    // ═════════════════════════════════════════════════════════════════════════

    public function test_inactivity_counts_local_days_since_the_last_app_use(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 14:00:00'); // 16:00 Prague

        $three = $this->student();
        $this->usedAppAt($three, '2026-10-04 10:00:00');

        $ten = $this->student();
        $this->usedAppAt($ten, '2026-09-27 10:00:00');

        $two = $this->student();
        $this->usedAppAt($two, '2026-10-05 10:00:00');

        // 23:30 UTC on the 4th is 01:30 on the 5th in Prague: 2 local days.
        $lateNight = $this->student();
        $this->usedAppAt($lateNight, '2026-10-04 23:30:00');

        // An old session and a fresh one: the fresh one counts.
        $twoDevices = $this->student();
        $this->usedAppAt($twoDevices, '2026-09-01 10:00:00');
        $this->usedAppAt($twoDevices, '2026-10-07 09:00:00');

        $teacher = $this->teacher();
        $this->usedAppAt($teacher, '2026-09-01 10:00:00');

        $neverUsed = $this->student();
        $neverUsed->createToken('app');

        $this->artisan('notifications:inactivity')->assertSuccessful();

        $byDays = $this->emitted('user.inactive')->keyBy(fn ($job) => $job->fields['days_inactive']);
        $this->assertSame([3, 10], $byDays->keys()->sort()->values()->all());
        $this->assertSame([$three->id], $byDays[3]->userIds);
        $this->assertSame([$ten->id], $byDays[10]->userIds);
        $this->assertSame('inactive:2026-10-07', $byDays[3]->dedupKey);
    }

    public function test_running_inactivity_twice_sends_one_comeback(): void
    {
        $this->fakePush(false);
        Carbon::setTestNow('2026-10-07 14:00:00');

        $student = $this->student();
        $this->usedAppAt($student, '2026-10-02 10:00:00');

        $this->artisan('notifications:inactivity')->assertSuccessful();
        $this->artisan('notifications:inactivity')->assertSuccessful();

        $this->assertSame(1, UserNotification::where('user_id', $student->id)->where('type_key', 'inactivity_comeback')->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // notifications:broadcast-deadlines
    // ═════════════════════════════════════════════════════════════════════════

    /** @param  list<User>  $recipients */
    private function sentBroadcast(array $recipients, array $attributes = []): NotificationBroadcast
    {
        $broadcast = NotificationBroadcast::create(array_merge([
            'title' => 'Odevzdání přihlášek',
            'body' => 'Přihlášky na výlet odevzdejte do pátku.',
            'type_key' => 'school_important_message',
            'channels' => ['push', 'feed'],
            'targeting' => ['school_ids' => [1], 'classroom_ids' => [], 'roles' => ['student']],
            'status' => NotificationBroadcast::STATUS_SENT,
            'recipient_count' => count($recipients),
            'sent_at' => Carbon::parse('2026-10-01 08:00:00'),
            'deadline_at' => Carbon::parse('2026-10-08 10:00:00'),
        ], $attributes));

        foreach ($recipients as $user) {
            NotificationBroadcastRecipient::create([
                'broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
                'role' => $user->role,
            ]);
        }

        return $broadcast;
    }

    public function test_a_deadline_within_24_hours_is_reminded_once(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 12:00:00');
        config(['notifications.broadcast.chunk' => 2]);

        $users = [$this->student(), $this->student(), $this->teacher()];
        $broadcast = $this->sentBroadcast($users);

        $this->artisan('notifications:broadcast-deadlines')->assertSuccessful();
        $this->artisan('notifications:broadcast-deadlines')->assertSuccessful();

        $jobs = $this->emitted('broadcast.deadline_approaching');
        $this->assertCount(2, $jobs); // 3 recipients in chunks of 2
        $this->assertSame([
            'broadcast_id' => $broadcast->id,
            'title' => 'Odevzdání přihlášek',
            'deadline' => '8. 10. 2026 12:00',
            'hours_left' => 22,
        ], $jobs[0]->fields);
        $this->assertSame(collect($users)->pluck('id')->sort()->values()->all(), $this->recipients('broadcast.deadline_approaching'));
        $this->assertNotNull($broadcast->fresh()->deadline_reminded_at);
    }

    public function test_deadlines_too_far_too_fresh_or_not_sent_are_left_alone(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $student = $this->student();

        $far = $this->sentBroadcast([$student], ['deadline_at' => Carbon::parse('2026-10-09 10:00:00')]);
        $fresh = $this->sentBroadcast([$student], ['sent_at' => Carbon::parse('2026-10-07 11:00:00')]);
        $draft = $this->sentBroadcast([$student], ['status' => NotificationBroadcast::STATUS_DRAFT]);
        $archived = $this->sentBroadcast([$student], ['status' => NotificationBroadcast::STATUS_ARCHIVED]);
        $past = $this->sentBroadcast([$student], ['deadline_at' => Carbon::parse('2026-10-07 11:00:00')]);

        $this->artisan('notifications:broadcast-deadlines')->assertSuccessful();

        $this->assertCount(0, $this->emitted('broadcast.deadline_approaching'));
        foreach ([$far, $fresh, $draft, $archived, $past] as $broadcast) {
            $this->assertNull($broadcast->fresh()->deadline_reminded_at);
        }
    }

    public function test_a_deadline_reminder_reaches_the_snapshot_through_the_pipeline(): void
    {
        $this->fakePush(false);
        Carbon::setTestNow('2026-10-07 12:00:00');

        $student = $this->student();
        $teacher = $this->teacher();
        $broadcast = $this->sentBroadcast([$student, $teacher]);

        $this->artisan('notifications:broadcast-deadlines')->assertSuccessful();

        $rows = UserNotification::where('type_key', 'org_deadline_reminder')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Blíží se termín: Odevzdání přihlášek', $rows[0]->title);
        $this->assertSame('Nezapomeň: Odevzdání přihlášek. Termín je 8. 10. 2026 12:00.', $rows[0]->body);
        $this->assertSame($broadcast->id, $rows[0]->broadcast_id);
        $this->assertSame('broadcast', $rows[0]->source);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // notifications:retry-stuck
    // ═════════════════════════════════════════════════════════════════════════

    private function deferredPush(User $user, array $attributes): UserNotification
    {
        $this->fakePush(false);
        $notification = $this->sendTo('school_important_message', [$user], ['title' => 'A', 'body' => 'B'], ['dedupKey' => Str::random(10)])->delivered[0];
        $notification->forceFill(array_merge(['push_status' => UserNotification::PUSH_DEFERRED], $attributes))->save();

        return $notification;
    }

    public function test_retry_stuck_requeues_queue_outages_and_overdue_deferrals_only(): void
    {
        Carbon::setTestNow('2026-10-07 12:00:00');
        $user = $this->student();

        $outage = $this->deferredPush($user, ['failure_reason' => NotificationService::REASON_QUEUE_UNAVAILABLE, 'deferred_until' => now()]);
        $overdue = $this->deferredPush($user, ['deferred_until' => now()->subMinutes(30)]);
        $recent = $this->deferredPush($user, ['deferred_until' => now()->subMinutes(5)]);
        $future = $this->deferredPush($user, ['deferred_until' => now()->addHours(3)]);
        $sent = $this->deferredPush($user, ['push_status' => UserNotification::PUSH_SENT, 'deferred_until' => now()->subHour()]);

        Queue::fake();
        $this->artisan('notifications:retry-stuck')->assertSuccessful();

        $ids = Queue::pushed(SendPushNotification::class)->map(fn ($job) => $job->userNotificationId)->sort()->values()->all();
        $this->assertSame([$outage->id, $overdue->id], $ids);
        $this->assertNull($outage->fresh()->failure_reason);
        $this->assertSame(now()->toDateTimeString(), $overdue->fresh()->deferred_until->toDateTimeString());
        $this->assertNotNull($recent->fresh()->deferred_until);
        $this->assertSame(UserNotification::PUSH_DEFERRED, $future->fresh()->push_status);
        $this->assertSame(UserNotification::PUSH_SENT, $sent->fresh()->push_status);
    }

    public function test_retry_stuck_stops_while_the_queue_is_still_down(): void
    {
        $user = $this->student();
        $first = $this->deferredPush($user, ['failure_reason' => NotificationService::REASON_QUEUE_UNAVAILABLE, 'deferred_until' => now()]);
        $second = $this->deferredPush($user, ['failure_reason' => NotificationService::REASON_QUEUE_UNAVAILABLE, 'deferred_until' => now()]);

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
        $this->app->forgetInstance(NotificationService::class);

        $this->artisan('notifications:retry-stuck')->assertSuccessful();

        $this->assertSame(NotificationService::REASON_QUEUE_UNAVAILABLE, $first->fresh()->failure_reason);
        $this->assertSame(NotificationService::REASON_QUEUE_UNAVAILABLE, $second->fresh()->failure_reason);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // notifications:queue-health
    // ═════════════════════════════════════════════════════════════════════════

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    private function pendingJobs(int $count, string $queue = 'notifications', ?int $availableAt = null): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'queue' => $queue,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $availableAt ?? now()->subMinutes(10)->getTimestamp(),
                'created_at' => now()->subMinutes(10)->getTimestamp(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('jobs')->insert($chunk);
        }
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'notifications',
            'payload' => '{}',
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);
    }

    public function test_queue_health_warns_on_a_backlog_and_on_new_failures(): void
    {
        $this->useDatabaseQueue();
        $this->pendingJobs(1001);
        // Delayed (quiet-hours) jobs are not a backlog.
        $this->pendingJobs(50, 'notifications', now()->addHours(5)->getTimestamp());
        $this->failedJob();

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('notifications')->andReturn($logger);

        $this->artisan('notifications:queue-health')->assertSuccessful();

        $logger->shouldHaveReceived('warning')->with('notifications.queue_backlog', Mockery::on(
            fn (array $context) => $context['pending_notifications'] === 1001 && $context['failed'] === 1,
        ))->once();
        $logger->shouldNotHaveReceived('warning', ['notifications.queue_failed_jobs_grew', Mockery::any()]);

        $this->failedJob();
        $this->artisan('notifications:queue-health')->assertSuccessful();

        $logger->shouldHaveReceived('warning')->with('notifications.queue_failed_jobs_grew', Mockery::on(
            fn (array $context) => $context['new_failed'] === 1,
        ))->once();
    }

    public function test_a_healthy_queue_logs_nothing(): void
    {
        $this->useDatabaseQueue();
        $this->pendingJobs(3);

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('notifications')->andReturn($logger);

        $this->artisan('notifications:queue-health')->assertSuccessful();
        $this->artisan('notifications:queue-health')->assertSuccessful();

        $logger->shouldNotHaveReceived('warning');
    }
}
