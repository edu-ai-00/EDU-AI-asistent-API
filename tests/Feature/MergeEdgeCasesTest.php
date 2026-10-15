<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\QuizAttempt;
use App\Models\StudentFsrsProfile;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserStats;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edge cases of the guest → full-account merge that the happy-path suites
 * (UserMergeTest, GuestUpgradeMergeTest) don't cover.
 */
class MergeEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(string $courseId): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => 'C ' . $courseId,
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ]);
    }

    private function guestAndTarget(): array
    {
        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $target = User::factory()->create(['is_guest' => false, 'email' => 'edge@example.test']);

        return [$guest, $target];
    }

    /**
     * Both users hold a quiz attempt with identical (course_id, started_at) —
     * an idempotency duplicate. The duplicate must be dropped and the merge
     * must still complete; previously the unique index aborted the whole
     * transaction and the merge silently never happened.
     */
    public function test_quiz_attempt_unique_collision_does_not_abort_merge(): void
    {
        $service = app(UserMergeService::class);
        [$guest, $target] = $this->guestAndTarget();

        $course = $this->makeCourse('c-collision');
        $startedAt = now()->subHour()->startOfSecond();

        foreach ([$guest, $target] as $u) {
            QuizAttempt::create([
                'user_id' => $u->id,
                'course_id' => $course->id,
                'total_questions' => 10,
                'correct_answers' => 5,
                'score_percent' => 50,
                'time_spent_seconds' => 60,
                'started_at' => $startedAt,
                'completed_at' => $startedAt->copy()->addMinutes(5),
                'answers' => [],
            ]);
        }

        // A second, non-colliding guest attempt must survive the reassign.
        QuizAttempt::create([
            'user_id' => $guest->id,
            'course_id' => $course->id,
            'total_questions' => 10,
            'correct_answers' => 8,
            'score_percent' => 80,
            'time_spent_seconds' => 90,
            'started_at' => $startedAt->copy()->addHours(2),
            'completed_at' => $startedAt->copy()->addHours(2)->addMinutes(4),
            'answers' => [],
        ]);

        $guestCourse = $this->makeCourse('c-guest-data');
        UserCourse::create([
            'user_id' => $guest->id,
            'course_id' => $guestCourse->id,
            'progress_percent' => 100,
            'status' => 'completed',
            'completed_lessons' => 5,
            'current_lesson_index' => 5,
            'time_spent_seconds' => 1000,
            'started_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $merge = $service->absorbGuest($guest, $target);

        $this->assertNotNull($merge, 'merge must survive the idempotency collision');
        $this->assertSame($target->id, $guest->fresh()->merged_into_user_id);
        // Guest's course reached the account.
        $this->assertDatabaseHas('user_courses', ['user_id' => $target->id, 'course_id' => $guestCourse->id]);
        // Colliding duplicate dropped, both remaining attempts owned by target.
        $this->assertSame(2, QuizAttempt::where('user_id', $target->id)->count());
        $this->assertSame(0, QuizAttempt::where('user_id', $guest->id)->count());
        $this->assertSame(1, (int) $merge->strategy_log['quiz_attempts']['duplicates_dropped']);
        $this->assertSame(1, (int) $merge->strategy_log['quiz_attempts']['reassigned']);
    }

    /**
     * Guest practiced (has FSRS calibration), target never did — the guest's
     * calibration must be adopted, not thrown away.
     */
    public function test_fsrs_profile_reassigned_when_target_has_none(): void
    {
        $service = app(UserMergeService::class);
        [$guest, $target] = $this->guestAndTarget();

        StudentFsrsProfile::create([
            'user_id' => $guest->id,
            'desired_retention' => 0.85,
            'maximum_interval' => 365,
            'enable_fuzz' => true,
            'enable_short_term' => true,
            'learning_steps' => [1, 10],
            'relearning_steps' => [10],
            'fsrs_weights' => [0.4, 0.6],
            'profile_version' => 1,
        ]);

        $this->assertNotNull($service->absorbGuest($guest, $target));

        $this->assertTrue(StudentFsrsProfile::where('user_id', $target->id)->exists());
        $this->assertFalse(StudentFsrsProfile::where('user_id', $guest->id)->exists());
    }

    /** When both sides have calibration, target's wins and source's is dropped. */
    public function test_fsrs_profile_target_wins_when_both_exist(): void
    {
        $service = app(UserMergeService::class);
        [$guest, $target] = $this->guestAndTarget();

        foreach ([[$guest, 0.80], [$target, 0.92]] as [$u, $retention]) {
            StudentFsrsProfile::create([
                'user_id' => $u->id,
                'desired_retention' => $retention,
                'maximum_interval' => 365,
                'enable_fuzz' => true,
                'enable_short_term' => true,
                'learning_steps' => [1, 10],
                'relearning_steps' => [10],
                'fsrs_weights' => [0.4, 0.6],
                'profile_version' => 1,
            ]);
        }

        $this->assertNotNull($service->absorbGuest($guest, $target));

        $profile = StudentFsrsProfile::where('user_id', $target->id)->first();
        $this->assertNotNull($profile);
        $this->assertEqualsWithDelta(0.92, $profile->desired_retention, 0.001);
        $this->assertSame(1, StudentFsrsProfile::count());
    }

    /**
     * Daily XP: the fresher day's counter wins when dates differ; same-day
     * counters sum. Total XP always sums.
     */
    public function test_daily_xp_freshest_date_wins(): void
    {
        $service = app(UserMergeService::class);
        [$guest, $target] = $this->guestAndTarget();

        $gs = UserStats::getOrCreateForUser($guest->id);
        $gs->xp_points = 300;
        $gs->daily_xp_date = now()->toDateString();
        $gs->daily_xp_amount = 300;
        $gs->save();

        $ts = UserStats::getOrCreateForUser($target->id);
        $ts->xp_points = 1000;
        $ts->daily_xp_date = now()->subDays(3)->toDateString();
        $ts->daily_xp_amount = 120;
        $ts->save();

        $service->absorbGuest($guest, $target);

        $after = UserStats::where('user_id', $target->id)->first();
        $this->assertSame(1300, (int) $after->xp_points);
        $this->assertSame(now()->toDateString(), $after->daily_xp_date?->toDateString());
        $this->assertSame(300, (int) $after->daily_xp_amount);
    }

    /**
     * Guest completed the course, target mid-way: status promoted, guest's
     * completed_at carried, time summed.
     */
    public function test_completed_course_promotes_status_and_completed_at(): void
    {
        $service = app(UserMergeService::class);
        [$guest, $target] = $this->guestAndTarget();

        $course = $this->makeCourse('c-promote');
        $completedAt = now()->subHours(2)->startOfSecond();

        UserCourse::create([
            'user_id' => $guest->id, 'course_id' => $course->id,
            'progress_percent' => 100, 'status' => 'completed',
            'completed_lessons' => 8, 'current_lesson_index' => 8,
            'time_spent_seconds' => 4000,
            'started_at' => now()->subDays(2), 'completed_at' => $completedAt,
        ]);
        UserCourse::create([
            'user_id' => $target->id, 'course_id' => $course->id,
            'progress_percent' => 30, 'status' => 'in_progress',
            'completed_lessons' => 2, 'current_lesson_index' => 2,
            'time_spent_seconds' => 900,
            'started_at' => now()->subDays(5), 'completed_at' => null,
        ]);

        $service->absorbGuest($guest, $target);

        $row = UserCourse::where('user_id', $target->id)->where('course_id', $course->id)->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame(100, (int) $row->progress_percent);
        $this->assertNotNull($row->completed_at);
        $this->assertSame(4900, (int) $row->time_spent_seconds);
    }

    /** Retry / double-fire: absorbing the same guest twice is a clean no-op. */
    public function test_absorb_guest_twice_is_noop(): void
    {
        $service = app(UserMergeService::class);
        [$guest, $target] = $this->guestAndTarget();

        $this->assertNotNull($service->absorbGuest($guest, $target));
        $this->assertNull($service->absorbGuest($guest->fresh(), $target->fresh()));
    }
}
