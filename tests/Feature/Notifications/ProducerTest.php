<?php

namespace Tests\Feature\Notifications;

use App\Jobs\EvaluateNotificationEvent;
use App\Models\Course;
use App\Models\MiniGame;
use App\Models\NotificationUserState;
use App\Models\PracticeCard;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Models\UserNotification;
use App\Models\UserStats;
use App\Notifications\StatusTracker;
use Carbon\Carbon;
use Database\Seeders\GpfVectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Event producers in the request handlers (spec §3.3, §6.4): each emits on
 * the crossing only, only for students, and never breaks the sync that
 * triggered it. Target actions mark the clicked notification they belong to.
 */
class ProducerTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        Queue::fake();
        Carbon::setTestNow('2026-10-07 10:00:00'); // Wednesday, ISO week 2026-W41
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

    private function stats(User $user, array $attributes): UserStats
    {
        return UserStats::create(array_merge([
            'user_id' => $user->id,
            'level' => 1,
            'xp_points' => 0,
            'courses_count' => 0,
            'streak_days' => 0,
            'achievements_count' => 0,
        ], $attributes));
    }

    private function syncStats(User $user, array $payload)
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/user/stats', $payload)->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Streak
    // ─────────────────────────────────────────────────────────────────────────

    public function test_streak_reaching_seven_emits_the_milestone(): void
    {
        $student = $this->student();
        $this->stats($student, ['streak_days' => 6]);

        $this->syncStats($student, ['streak_days' => 7]);

        $jobs = $this->emitted('streak.milestone');
        $this->assertCount(1, $jobs);
        $this->assertSame(['streak_days' => 7], $jobs[0]->fields);
        $this->assertSame([$student->id], $jobs[0]->userIds);
    }

    public function test_streak_without_a_crossing_emits_nothing(): void
    {
        $student = $this->student();
        $this->stats($student, ['streak_days' => 7]);

        $this->syncStats($student, ['streak_days' => 8]);
        $this->syncStats($student, ['streak_days' => 8]);

        $this->assertCount(0, $this->emitted('streak.milestone'));
    }

    public function test_streak_jumping_over_two_milestones_emits_only_the_highest(): void
    {
        $student = $this->student();
        $this->stats($student, ['streak_days' => 5]);

        $this->syncStats($student, ['streak_days' => 31]);

        $jobs = $this->emitted('streak.milestone');
        $this->assertCount(1, $jobs);
        $this->assertSame(30, $jobs[0]->fields['streak_days']);
    }

    public function test_a_teacher_syncing_stats_emits_nothing(): void
    {
        $teacher = $this->teacher();
        $this->stats($teacher, ['streak_days' => 6, 'xp_points' => 0]);

        $this->syncStats($teacher, ['streak_days' => 7, 'xp_points' => 1000, 'daily_xp_date' => '2026-10-07', 'daily_xp_amount' => 80]);

        Queue::assertNotPushed(EvaluateNotificationEvent::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Daily goal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_daily_xp_crossing_the_target_emits_a_daily_goal(): void
    {
        $student = $this->student();
        $this->stats($student, ['daily_xp_date' => '2026-10-07', 'daily_xp_amount' => 40]);

        $this->syncStats($student, ['daily_xp_date' => '2026-10-07', 'daily_xp_amount' => 55]);

        $jobs = $this->emitted('goal.completed');
        $this->assertCount(1, $jobs);
        $this->assertSame(['period' => 'daily', 'xp' => 55, 'target' => 50], $jobs[0]->fields);
        $this->assertSame('goal:daily:2026-10-07', $jobs[0]->dedupKey);
    }

    public function test_daily_xp_already_above_the_target_emits_nothing_more(): void
    {
        $student = $this->student();
        $this->stats($student, ['daily_xp_date' => '2026-10-07', 'daily_xp_amount' => 55]);

        $this->syncStats($student, ['daily_xp_date' => '2026-10-07', 'daily_xp_amount' => 70]);

        $this->assertCount(0, $this->emitted('goal.completed'));
    }

    public function test_a_new_day_starting_above_the_target_counts(): void
    {
        $student = $this->student();
        $this->stats($student, ['daily_xp_date' => '2026-10-06', 'daily_xp_amount' => 90]);

        $this->syncStats($student, ['daily_xp_date' => '2026-10-07', 'daily_xp_amount' => 60]);

        $this->assertCount(1, $this->emitted('goal.completed'));
    }

    public function test_a_late_sync_of_an_old_day_is_not_news(): void
    {
        $student = $this->student();
        $this->stats($student, ['daily_xp_date' => '2026-10-01', 'daily_xp_amount' => 0]);

        $this->syncStats($student, ['daily_xp_date' => '2026-10-02', 'daily_xp_amount' => 80]);

        $this->assertCount(0, $this->emitted('goal.completed'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Weekly goal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weekly_goal_counts_from_the_baseline_and_fires_once_per_week(): void
    {
        $student = $this->student();
        $this->stats($student, ['xp_points' => 1000]);

        // First growth of the week: baseline = 1000, week XP 100.
        $this->syncStats($student, ['xp_points' => 1100]);
        $state = NotificationUserState::find($student->id);
        $this->assertSame('2026-W41', $state->week_key);
        $this->assertSame(1000, $state->week_start_xp);
        $this->assertCount(0, $this->emitted('goal.completed'));

        // 260 this week — crossed.
        $this->syncStats($student, ['xp_points' => 1260]);
        $jobs = $this->emitted('goal.completed');
        $this->assertCount(1, $jobs);
        $this->assertSame(['period' => 'weekly', 'xp' => 260, 'target' => 250], $jobs[0]->fields);
        $this->assertSame('goal:weekly:2026-W41', $jobs[0]->dedupKey);

        // Further growth the same week: nothing new.
        $this->syncStats($student, ['xp_points' => 1400]);
        $this->assertCount(1, $this->emitted('goal.completed'));

        // Next week: a fresh baseline at 1400.
        Carbon::setTestNow('2026-10-13 09:00:00');
        $this->syncStats($student, ['xp_points' => 1500]);
        $state->refresh();
        $this->assertSame('2026-W42', $state->week_key);
        $this->assertSame(1400, $state->week_start_xp);
        $this->assertCount(1, $this->emitted('goal.completed'));
    }

    public function test_the_week_follows_the_notification_timezone(): void
    {
        $student = $this->student();
        $this->stats($student, ['xp_points' => 0]);

        // Sunday 23:30 UTC is already Monday 01:30 in Prague — week 42.
        Carbon::setTestNow('2026-10-11 23:30:00');
        $this->syncStats($student, ['xp_points' => 10]);

        $this->assertSame('2026-W42', NotificationUserState::find($student->id)->week_key);
    }

    public function test_a_failing_hook_never_breaks_the_stats_sync(): void
    {
        $student = $this->student();
        $this->stats($student, ['xp_points' => 0]);
        Schema::drop('notification_user_states');

        $response = $this->syncStats($student, ['xp_points' => 500]);

        $this->assertSame(500, $response->json('stats.xp_points'));
        $this->assertSame(500, UserStats::where('user_id', $student->id)->value('xp_points'));
    }

    public function test_xp_growth_is_learning_activity_for_a_clicked_notification(): void
    {
        $student = $this->student();
        $this->stats($student, ['xp_points' => 0]);
        $notification = $this->clickedNotification($student, 'inactivity_comeback');

        $this->syncStats($student, ['xp_points' => 10]);

        $this->assertNotNull($notification->fresh()->target_action_at);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Courses
    // ─────────────────────────────────────────────────────────────────────────

    private function course(?string $pin = 'ABC123'): Course
    {
        return Course::create([
            'course_id' => 'ZLOMKY_' . Str::random(4),
            'name' => 'Zlomky',
            'status' => 'published',
            'version' => 1,
            'language' => 'cs',
            'pin' => $pin,
            'data' => ['lessons' => []],
        ]);
    }

    private function enrol(User $user, Course $course, array $attributes = []): UserCourse
    {
        return UserCourse::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'in_progress',
            'progress_percent' => 0,
            'completed_lessons' => 0,
            'current_lesson_index' => 0,
            'time_spent_seconds' => 0,
        ], $attributes));
    }

    public function test_progress_crossing_half_emits_the_milestone_with_course_fields(): void
    {
        $student = $this->student();
        $course = $this->course('ABC123');
        $this->enrol($student, $course, ['progress_percent' => 40]);

        Sanctum::actingAs($student);
        $this->putJson("/api/user/courses/{$course->id}", ['progress_percent' => 55])->assertOk();

        $jobs = $this->emitted('course.progress_milestone');
        $this->assertCount(1, $jobs);
        $this->assertSame([
            'course_id' => $course->id,
            'course_name' => 'Zlomky',
            'course_code' => 'ABC123',
            'percent' => 50,
        ], $jobs[0]->fields);
        $this->assertCount(0, $this->emitted('course.completed'));
    }

    public function test_progress_without_a_crossing_emits_nothing_and_a_missing_pin_is_omitted(): void
    {
        $student = $this->student();
        $course = $this->course(null);
        $this->enrol($student, $course, ['progress_percent' => 60]);

        Sanctum::actingAs($student);
        $this->putJson("/api/user/courses/{$course->id}", ['progress_percent' => 70])->assertOk();
        $this->assertCount(0, $this->emitted('course.progress_milestone'));

        $other = $this->course(null);
        $this->enrol($student, $other, ['progress_percent' => 10]);
        $this->putJson("/api/user/courses/{$other->id}", ['progress_percent' => 50])->assertOk();

        $jobs = $this->emitted('course.progress_milestone');
        $this->assertCount(1, $jobs);
        $this->assertArrayNotHasKey('course_code', $jobs[0]->fields);
    }

    public function test_completing_a_course_emits_completed_once_and_no_halfway(): void
    {
        $student = $this->student();
        $course = $this->course();
        $this->enrol($student, $course, ['progress_percent' => 10]);

        Sanctum::actingAs($student);
        $this->putJson("/api/user/courses/{$course->id}", ['status' => 'completed'])->assertOk();
        $this->putJson("/api/user/courses/{$course->id}", ['status' => 'completed', 'progress_percent' => 100])->assertOk();

        $jobs = $this->emitted('course.completed');
        $this->assertCount(1, $jobs);
        $this->assertSame($course->id, $jobs[0]->fields['course_id']);
        $this->assertCount(0, $this->emitted('course.progress_milestone'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Study sessions (heartbeats)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, string>> */
    private function heartbeats(Carbon $from, int $count, int $everySeconds = 60): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'client_uuid' => (string) Str::uuid(),
                'course_id' => 'ZLOMKY',
                'occurred_at' => $from->copy()->addSeconds($i * $everySeconds)->toIso8601String(),
            ];
        }

        return $rows;
    }

    public function test_a_long_current_session_emits_once(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);

        $start = now()->subMinutes(46);
        $this->postJson('/api/work/heartbeats', ['heartbeats' => $this->heartbeats($start, 47)])->assertOk();

        $jobs = $this->emitted('study.long_session');
        $this->assertCount(1, $jobs);
        $this->assertGreaterThanOrEqual(45, $jobs[0]->fields['minutes']);
        $this->assertSame($start->copy()->utc()->toIso8601String(), $jobs[0]->fields['session_started_at']);

        // The session goes on — no second suggestion for it.
        Carbon::setTestNow(now()->addMinutes(5));
        $this->postJson('/api/work/heartbeats', ['heartbeats' => $this->heartbeats(now()->subMinutes(4), 5)])->assertOk();
        $this->assertCount(1, $this->emitted('study.long_session'));
    }

    public function test_a_short_session_emits_nothing(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);

        $this->postJson('/api/work/heartbeats', ['heartbeats' => $this->heartbeats(now()->subMinutes(20), 21)])->assertOk();

        $this->assertCount(0, $this->emitted('study.long_session'));
    }

    public function test_a_gap_over_thirty_minutes_starts_a_new_session(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);

        // 30 min, a 40 min break, then 20 min up to now: the current session is 20 min.
        $rows = array_merge(
            $this->heartbeats(now()->subMinutes(90), 31),
            $this->heartbeats(now()->subMinutes(20), 21),
        );
        $this->postJson('/api/work/heartbeats', ['heartbeats' => $rows])->assertOk();

        $this->assertCount(0, $this->emitted('study.long_session'));
    }

    public function test_a_late_offline_batch_of_a_finished_session_emits_nothing(): void
    {
        $student = $this->student();
        Sanctum::actingAs($student);

        $this->postJson('/api/work/heartbeats', ['heartbeats' => $this->heartbeats(now()->subHours(5), 60)])->assertOk();

        $this->assertCount(0, $this->emitted('study.long_session'));
    }

    public function test_a_queue_outage_never_breaks_course_or_heartbeat_syncs(): void
    {
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class implements \Illuminate\Contracts\Bus\Dispatcher
        {
            public function dispatch($command) { throw new \RuntimeException('queue down'); }
            public function dispatchSync($command, $handler = null) { throw new \RuntimeException('queue down'); }
            public function dispatchNow($command, $handler = null) { throw new \RuntimeException('queue down'); }
            public function hasCommandHandler($command) { return false; }
            public function getCommandHandler($command) { return false; }
            public function pipeThrough(array $pipes) { return $this; }
            public function map(array $map) { return $this; }
        });

        $student = $this->student();
        $course = $this->course();
        $this->enrol($student, $course, ['progress_percent' => 10]);
        Sanctum::actingAs($student);

        $this->putJson("/api/user/courses/{$course->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('user_course.status', 'completed');

        $this->postJson('/api/work/heartbeats', ['heartbeats' => $this->heartbeats(now()->subMinutes(50), 51)])
            ->assertOk()
            ->assertJsonPath('accepted', 51);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Topic mastery (ELO profile)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{0: list<?float>, 1: list<int>} */
    private function profile(array $elo = [], array $counts = []): array
    {
        $e = array_fill(0, 35, null);
        $c = array_fill(0, 35, 0);
        foreach ($elo as $i => $v) {
            $e[$i] = $v;
        }
        foreach ($counts as $i => $v) {
            $c[$i] = $v;
        }

        return [$e, $c];
    }

    public function test_a_dimension_crossing_into_mastery_emits_topic_mastered_with_its_gpf_name(): void
    {
        $this->seed(GpfVectorSeeder::class);
        $student = $this->student();
        [$elo, $count] = $this->profile([4 => 7.5, 5 => 9.0], [4 => 12, 5 => 15]);
        UserEloProfile::create(['user_id' => $student->id, 'profil_elo' => $elo, 'profil_pocet' => $count]);

        [$elo, $count] = $this->profile([4 => 8.2, 5 => 9.1], [4 => 13, 5 => 16]);
        Sanctum::actingAs($student);
        $this->putJson('/api/user/elo-profile', ['profil_elo' => $elo, 'profil_pocet' => $count])->assertOk();

        $jobs = $this->emitted('topic.mastered');
        $this->assertCount(1, $jobs);
        $this->assertSame(['topic_key' => 'dim:4', 'topic_name' => 'Zlomky – určí a znázorní'], $jobs[0]->fields);
    }

    public function test_mastery_needs_enough_answers_and_an_unchanged_profile_emits_nothing(): void
    {
        $student = $this->student();
        [$elo, $count] = $this->profile([4 => 7.0], [4 => 5]);
        UserEloProfile::create(['user_id' => $student->id, 'profil_elo' => $elo, 'profil_pocet' => $count]);
        Sanctum::actingAs($student);

        // Rating high enough, too few answers.
        [$elo, $count] = $this->profile([4 => 8.5], [4 => 9]);
        $this->putJson('/api/user/elo-profile', ['profil_elo' => $elo, 'profil_pocet' => $count])->assertOk();
        // Same profile again.
        $this->putJson('/api/user/elo-profile', ['profil_elo' => $elo, 'profil_pocet' => $count])->assertOk();

        $this->assertCount(0, $this->emitted('topic.mastered'));

        // Tenth answer: mastered — without a GPF vector the name is left to the fallback.
        [$elo, $count] = $this->profile([4 => 8.5], [4 => 10]);
        $this->putJson('/api/user/elo-profile', ['profil_elo' => $elo, 'profil_pocet' => $count])->assertOk();

        $jobs = $this->emitted('topic.mastered');
        $this->assertCount(1, $jobs);
        $this->assertSame(['topic_key' => 'dim:4'], $jobs[0]->fields);
    }

    public function test_a_failing_mastery_lookup_never_breaks_the_profile_sync(): void
    {
        $this->seed(GpfVectorSeeder::class);
        Schema::drop('vector_dimensions');
        $student = $this->student();
        Sanctum::actingAs($student);

        [$elo, $count] = $this->profile([4 => 9.0], [4 => 20]);
        $this->putJson('/api/user/elo-profile', ['profil_elo' => $elo, 'profil_pocet' => $count])
            ->assertOk()
            ->assertJsonPath('profil_pocet.4', 20);

        $this->assertSame(20, UserEloProfile::where('user_id', $student->id)->first()->profil_pocet[4]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Target actions
    // ─────────────────────────────────────────────────────────────────────────

    private function clickedNotification(User $user, string $typeKey): UserNotification
    {
        $this->fakePush(false);
        $notification = $this->sendTo($typeKey, [$user], [], ['dedupKey' => Str::random(8)])->delivered[0];
        app(StatusTracker::class)->transition($notification, UserNotification::STATUS_CLICKED);

        return $notification->fresh();
    }

    public function test_a_quiz_attempt_completes_the_quiz_nudge(): void
    {
        $student = $this->student();
        $notification = $this->clickedNotification($student, 'activation_daily_quiz');

        Sanctum::actingAs($student);
        $this->postJson('/api/user/quiz-attempts', [
            'course_id' => 'ZLOMKY',
            'total_questions' => 10,
            'correct_answers' => 8,
            'score_percent' => 80,
        ])->assertCreated();

        $this->assertNotNull($notification->fresh()->target_action_at);
    }

    public function test_a_practice_review_completes_the_practice_nudge(): void
    {
        $student = $this->student();
        $notification = $this->clickedNotification($student, 'activation_daily_practice');
        $card = PracticeCard::create([
            'user_id' => $student->id,
            'course_id' => 'ZLOMKY',
            'lesson_id' => 'L1',
            'block_id' => 'B1',
            'source_type' => 'quiz',
        ]);

        Sanctum::actingAs($student);
        $this->postJson('/api/user/review-logs', ['logs' => [[
            'card_id' => $card->id,
            'rating' => 3,
            'shown_at' => now()->subMinute()->toIso8601String(),
            'reviewed_at' => now()->toIso8601String(),
            'response_time_sec' => 12,
            'repetition_number' => 1,
            'stability_after' => 2.5,
            'difficulty_after' => 5.0,
            'next_due_date' => now()->addDays(2)->toIso8601String(),
            'interval_days' => 2,
        ]]])->assertCreated();

        $this->assertNotNull($notification->fresh()->target_action_at);
    }

    public function test_a_game_play_completes_the_game_nudge(): void
    {
        $student = $this->student();
        $notification = $this->clickedNotification($student, 'activation_game');
        MiniGame::factory()->create(['code' => 'kulicka']);

        Sanctum::actingAs($student);
        $this->postJson('/api/mini-games/kulicka/play')->assertOk();

        $this->assertNotNull($notification->fresh()->target_action_at);
    }

    public function test_lesson_progress_is_learning_activity(): void
    {
        $student = $this->student();
        $notification = $this->clickedNotification($student, 'learning_reminder');

        Sanctum::actingAs($student);
        $this->postJson('/api/user/progress', ['progress' => [[
            'course_id' => 'ZLOMKY',
            'lesson_id' => 'L1',
            'progress_percent' => 30,
        ]]])->assertOk();

        $this->assertNotNull($notification->fresh()->target_action_at);
    }
}
