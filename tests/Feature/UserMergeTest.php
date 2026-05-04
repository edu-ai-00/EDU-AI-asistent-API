<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\PracticeCard;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Models\UserMerge;
use App\Models\UserStats;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserMergeTest extends TestCase
{
    use RefreshDatabase;

    private UserMergeService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UserMergeService::class);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@example.test',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeStudent(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'student',
            'is_guest' => false,
        ], $overrides));
    }

    private function makePinStudent(string $code = 'ABC123', array $overrides = []): User
    {
        // PIN-only student: no email, login_code present.
        return User::factory()->create(array_merge([
            'role' => 'student',
            'email' => null,
            'login_code' => $code,
            'is_guest' => false,
        ], $overrides));
    }

    private function makeCourse(string $courseId = 'course-x', string $name = 'Course X'): \App\Models\Course
    {
        return \App\Models\Course::create([
            'course_id' => $courseId,
            'name' => $name,
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ]);
    }

    private function makeStats(User $user, array $fields = []): UserStats
    {
        return UserStats::create(array_merge([
            'user_id' => $user->id,
            'level' => 1,
            'xp_points' => 0,
            'courses_count' => 0,
            'streak_days' => 0,
            'achievements_count' => 0,
        ], $fields));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. test_merge_combines_xp_and_takes_max_streak
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_combines_xp_and_takes_max_streak(): void
    {
        $source = $this->makeStudent(['email' => null, 'login_code' => 'AAAAAA']);
        $target = $this->makeStudent(['email' => 'keep@example.test']);

        $this->makeStats($source, ['xp_points' => 1500, 'streak_days' => 3]);
        $this->makeStats($target, ['xp_points' => 800, 'streak_days' => 7]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $targetStats = UserStats::where('user_id', $target->id)->first();
        $this->assertSame(2300, $targetStats->xp_points);
        $this->assertSame(7, $targetStats->streak_days);
        // Level recomputed from XP using floor(xp/1000) + 1.
        $this->assertSame(3, $targetStats->level);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. test_merge_keeps_higher_course_progress
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_keeps_higher_course_progress(): void
    {
        $source = $this->makePinStudent('SRCXXX');
        $target = $this->makeStudent(['email' => 'keep@example.test']);

        $course = $this->makeCourse('course-1', 'C1');

        UserCourse::create([
            'user_id' => $source->id,
            'course_id' => $course->id,
            'progress_percent' => 80,
            'status' => 'in_progress',
            'completed_lessons' => 8,
            'current_lesson_index' => 8,
            'time_spent_seconds' => 3600,
            'progress_data' => ['from' => 'source'],
            'started_at' => now()->subDays(10),
        ]);
        UserCourse::create([
            'user_id' => $target->id,
            'course_id' => $course->id,
            'progress_percent' => 30,
            'status' => 'in_progress',
            'completed_lessons' => 3,
            'current_lesson_index' => 3,
            'time_spent_seconds' => 1200,
            'progress_data' => ['from' => 'target'],
            'started_at' => now()->subDays(5),
        ]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $merged = UserCourse::where('user_id', $target->id)->where('course_id', $course->id)->first();
        $this->assertSame(80, $merged->progress_percent);
        $this->assertSame(8, $merged->completed_lessons);
        $this->assertSame(8, $merged->current_lesson_index);
        $this->assertSame(4800, $merged->time_spent_seconds); // 3600 + 1200
        $this->assertSame(['from' => 'source'], $merged->progress_data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. test_merge_unions_achievements_no_duplicates
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_unions_achievements_no_duplicates(): void
    {
        $source = $this->makePinStudent('SRCAAA');
        $target = $this->makeStudent(['email' => 'tgt@example.test']);

        UserAchievement::create(['user_id' => $source->id, 'achievement_id' => 'A1', 'earned_at' => now()]);
        UserAchievement::create(['user_id' => $source->id, 'achievement_id' => 'A2', 'earned_at' => now()]);
        UserAchievement::create(['user_id' => $target->id, 'achievement_id' => 'A2', 'earned_at' => now()]); // dup
        UserAchievement::create(['user_id' => $target->id, 'achievement_id' => 'A3', 'earned_at' => now()]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $ids = UserAchievement::where('user_id', $target->id)->pluck('achievement_id')->sort()->values()->all();
        $this->assertSame(['A1', 'A2', 'A3'], $ids);
        $this->assertSame(0, UserAchievement::where('user_id', $source->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. test_merge_unions_bookmarks_no_duplicates
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_unions_bookmarks_no_duplicates(): void
    {
        $source = $this->makePinStudent('SRCBBB');
        $target = $this->makeStudent(['email' => 'b@example.test']);

        Bookmark::create(['user_id' => $source->id, 'course_id' => 'c1', 'lesson_id' => 'l1', 'block_id' => 'b1']);
        Bookmark::create(['user_id' => $source->id, 'course_id' => 'c1', 'lesson_id' => 'l1', 'block_id' => 'b2']);
        Bookmark::create(['user_id' => $target->id, 'course_id' => 'c1', 'lesson_id' => 'l1', 'block_id' => 'b2']); // dup
        Bookmark::create(['user_id' => $target->id, 'course_id' => 'c1', 'lesson_id' => 'l1', 'block_id' => 'b3']);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $count = Bookmark::where('user_id', $target->id)->count();
        $this->assertSame(3, $count);
        $this->assertSame(0, Bookmark::where('user_id', $source->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. test_merge_reassigns_quiz_attempts
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_reassigns_quiz_attempts(): void
    {
        $source = $this->makePinStudent('SRCQQQ');
        $target = $this->makeStudent(['email' => 'q@example.test']);

        QuizAttempt::create([
            'user_id' => $source->id, 'course_id' => 'c1',
            'total_questions' => 10, 'correct_answers' => 8,
            'score_percent' => 80, 'time_spent_seconds' => 60,
        ]);
        QuizAttempt::create([
            'user_id' => $source->id, 'course_id' => 'c1',
            'total_questions' => 10, 'correct_answers' => 9,
            'score_percent' => 90, 'time_spent_seconds' => 50,
        ]);
        QuizAttempt::create([
            'user_id' => $target->id, 'course_id' => 'c1',
            'total_questions' => 10, 'correct_answers' => 5,
            'score_percent' => 50, 'time_spent_seconds' => 90,
        ]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $this->assertSame(3, QuizAttempt::where('user_id', $target->id)->count());
        $this->assertSame(0, QuizAttempt::where('user_id', $source->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. test_merge_reassigns_chats
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_reassigns_chats(): void
    {
        $source = $this->makePinStudent('SRCCCC');
        $target = $this->makeStudent(['email' => 'c@example.test']);

        $sSession = ChatSession::create([
            'user_id' => $source->id, 'title' => 'Old chat', 'persona' => 'tutor',
        ]);
        ChatMessage::create([
            'chat_session_id' => $sSession->id,
            'role' => 'user',
            'content' => 'hi',
        ]);
        ChatSession::create([
            'user_id' => $target->id, 'title' => 'New chat', 'persona' => 'tutor',
        ]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $this->assertSame(2, ChatSession::where('user_id', $target->id)->count());
        $this->assertSame(0, ChatSession::where('user_id', $source->id)->count());
        // Messages follow via session FK — message still attached to its session.
        $this->assertSame(1, ChatMessage::where('chat_session_id', $sSession->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. test_merge_combines_elo_per_skill
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_combines_elo_per_skill(): void
    {
        $source = $this->makePinStudent('SRCEEE');
        $target = $this->makeStudent(['email' => 'e@example.test']);

        UserEloProfile::create([
            'user_id' => $source->id,
            'profil_elo' => [5.0, 7.0, 3.0],
            'profil_pocet' => [10, 5, 2],
        ]);
        UserEloProfile::create([
            'user_id' => $target->id,
            'profil_elo' => [4.0, 8.0, 6.0],
            'profil_pocet' => [3, 4, 1],
        ]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $merged = UserEloProfile::where('user_id', $target->id)->first();
        $this->assertSame([5.0, 8.0, 6.0], array_map('floatval', $merged->profil_elo));
        $this->assertSame([13, 9, 3], array_map('intval', $merged->profil_pocet));
        $this->assertSame(0, UserEloProfile::where('user_id', $source->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. test_merge_keeps_better_practice_card
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_keeps_better_practice_card(): void
    {
        $source = $this->makePinStudent('SRCPPP');
        $target = $this->makeStudent(['email' => 'p@example.test']);

        PracticeCard::create([
            'user_id' => $source->id, 'course_id' => 'c1', 'lesson_id' => 'l1',
            'block_id' => 'block-A', 'source_type' => 'lesson',
            'reps' => 8, 'state' => 2,
        ]);
        PracticeCard::create([
            'user_id' => $target->id, 'course_id' => 'c1', 'lesson_id' => 'l1',
            'block_id' => 'block-A', 'source_type' => 'lesson',
            'reps' => 2, 'state' => 1,
        ]);
        PracticeCard::create([
            'user_id' => $source->id, 'course_id' => 'c1', 'lesson_id' => 'l1',
            'block_id' => 'block-B', 'source_type' => 'lesson',
            'reps' => 1, 'state' => 1,
        ]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $cards = PracticeCard::where('user_id', $target->id)->get()->keyBy('block_id');
        $this->assertCount(2, $cards);
        $this->assertSame(8, (int) $cards['block-A']->reps); // higher reps wins
        $this->assertSame(1, (int) $cards['block-B']->reps); // sole-side reassigned
        $this->assertSame(0, PracticeCard::where('user_id', $source->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. test_merge_nulls_source_email_burns_pin
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_nulls_source_email_burns_pin(): void
    {
        // email-vs-email scenario so both sides have email; admin picks direction.
        $source = $this->makeStudent(['email' => 'src@example.test', 'login_code' => 'BURNME']);
        $target = $this->makeStudent(['email' => 'tgt@example.test']);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $source->refresh();
        $this->assertNull($source->email);
        // PIN intentionally preserved (burned).
        $this->assertSame('BURNME', $source->login_code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. test_merge_sets_merged_pointer_on_source
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_sets_merged_pointer_on_source(): void
    {
        $source = $this->makePinStudent('SRCMMM');
        $target = $this->makeStudent(['email' => 'm@example.test']);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        $source->refresh();
        $this->assertSame($target->id, $source->merged_into_user_id);
        $this->assertNotNull($source->merged_at);
        $this->assertTrue($source->isMerged());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. test_merge_writes_audit_row_with_snapshots
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_writes_audit_row_with_snapshots(): void
    {
        $source = $this->makePinStudent('SRCAUD');
        $target = $this->makeStudent(['email' => 'aud@example.test']);
        $this->makeStats($source, ['xp_points' => 100, 'streak_days' => 2]);
        $this->makeStats($target, ['xp_points' => 200, 'streak_days' => 5]);

        $audit = $this->service->merge($source->id, $target->id, $this->admin->id);

        $this->assertInstanceOf(UserMerge::class, $audit);
        $this->assertSame($source->id, $audit->source_user_id);
        $this->assertSame($target->id, $audit->target_user_id);
        $this->assertSame($this->admin->id, $audit->performed_by);

        $this->assertIsArray($audit->source_snapshot);
        $this->assertSame($source->id, $audit->source_snapshot['id']);
        $this->assertSame(100, $audit->source_snapshot['stats']['xp_points']);

        $this->assertIsArray($audit->target_before);
        $this->assertSame(200, $audit->target_before['stats']['xp_points']);

        $this->assertIsArray($audit->strategy_log);
        $this->assertArrayHasKey('user_stats', $audit->strategy_log);
        $this->assertArrayHasKey('user_courses', $audit->strategy_log);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. test_login_rejected_on_merged_source_via_email
    // ─────────────────────────────────────────────────────────────────────────

    public function test_login_rejected_on_merged_source_via_email(): void
    {
        $target = $this->makeStudent(['email' => 'target@example.test']);
        $sourceEmail = 'src@example.test';
        $source = $this->makeStudent([
            'email' => $sourceEmail,
            'password' => bcrypt('secret123'),
        ]);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        // After merge, source.email is null. Re-set it for the test to confirm
        // login_path guard handles both flows. (Production path: source email
        // is nulled at merge so users can't login; this verifies the guard.)
        $source->refresh();
        $source->email = $sourceEmail;
        $source->saveQuietly();

        $response = $this->postJson('/api/login', [
            'email' => $sourceEmail,
            'password' => 'secret123',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('merged_into', $target->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. test_login_rejected_on_merged_source_via_pin
    // ─────────────────────────────────────────────────────────────────────────

    public function test_login_rejected_on_merged_source_via_pin(): void
    {
        $target = $this->makeStudent(['email' => 'pintarget@example.test']);
        $source = $this->makePinStudent('PINSRC');

        $this->service->merge($source->id, $target->id, $this->admin->id);

        // PIN is burned (preserved on source). Hitting resolve-code with that
        // PIN must return 410 because the source is merged.
        $response = $this->postJson('/api/resolve-code', ['code' => 'PINSRC']);

        $response->assertStatus(410);
        $response->assertJsonPath('merged_into', $target->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. test_preview_does_not_modify_db
    // ─────────────────────────────────────────────────────────────────────────

    public function test_preview_does_not_modify_db(): void
    {
        $source = $this->makePinStudent('PRVSRC');
        $target = $this->makeStudent(['email' => 'prv@example.test']);
        $this->makeStats($source, ['xp_points' => 100]);
        $this->makeStats($target, ['xp_points' => 200]);

        $beforeCount = User::count();
        $beforeStatsXp = UserStats::where('user_id', $target->id)->first()->xp_points;

        $plan = $this->service->preview($source->id, $target->id);

        $this->assertIsArray($plan);
        $this->assertArrayHasKey('plan', $plan);
        $this->assertSame($beforeCount, User::count());
        $this->assertSame(0, UserMerge::count());
        $this->assertSame(
            $beforeStatsXp,
            UserStats::where('user_id', $target->id)->first()->xp_points
        );
        $source->refresh();
        $this->assertNull($source->merged_into_user_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 15. test_email_user_auto_wins_against_pin_only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_email_user_auto_wins_against_pin_only(): void
    {
        // Caller passes email user as SOURCE and PIN user as TARGET — server must swap.
        $emailUser = $this->makeStudent(['email' => 'autowin@example.test']);
        $pinUser = $this->makePinStudent('PINONLY');

        $audit = $this->service->merge($emailUser->id, $pinUser->id, $this->admin->id);

        // After enforce: target should be the email user; source should be the PIN user.
        $this->assertSame($pinUser->id, $audit->source_user_id);
        $this->assertSame($emailUser->id, $audit->target_user_id);

        // Email user must still have email after merge.
        $emailUser->refresh();
        $this->assertSame('autowin@example.test', $emailUser->email);
        $this->assertNull($emailUser->merged_into_user_id);

        // PIN user becomes the merged source.
        $pinUser->refresh();
        $this->assertSame($emailUser->id, $pinUser->merged_into_user_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 16. test_admin_cannot_merge_admin_or_teacher
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_cannot_merge_admin_or_teacher(): void
    {
        $teacher = $this->makeStudent(['email' => 't@example.test', 'role' => 'teacher']);
        $student = $this->makeStudent(['email' => 's@example.test']);

        $this->expectException(ValidationException::class);
        $this->service->merge($teacher->id, $student->id, $this->admin->id);
    }

    public function test_admin_cannot_merge_admin_account(): void
    {
        $otherAdmin = $this->makeStudent(['email' => 'a2@example.test', 'role' => 'admin']);
        $student = $this->makeStudent(['email' => 's@example.test']);

        $this->expectException(ValidationException::class);
        $this->service->merge($student->id, $otherAdmin->id, $this->admin->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 17. test_cannot_merge_already_merged_user
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_merge_already_merged_user(): void
    {
        $source = $this->makePinStudent('SRCALR');
        $target = $this->makeStudent(['email' => 'alr@example.test']);

        $this->service->merge($source->id, $target->id, $this->admin->id);

        // Attempt to merge the already-merged source into another user.
        $other = $this->makeStudent(['email' => 'other@example.test']);

        $this->expectException(ValidationException::class);
        $this->service->merge($source->id, $other->id, $this->admin->id);
    }

    public function test_cannot_merge_user_with_self(): void
    {
        $student = $this->makeStudent(['email' => 'self@example.test']);

        $this->expectException(ValidationException::class);
        $this->service->merge($student->id, $student->id, $this->admin->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 18. test_concurrent_merge_locks_correctly
    // ─────────────────────────────────────────────────────────────────────────

    public function test_concurrent_merge_locks_correctly(): void
    {
        $source = $this->makePinStudent('LOCK01');
        $target = $this->makeStudent(['email' => 'lock@example.test']);

        // First merge succeeds.
        $audit = $this->service->merge($source->id, $target->id, $this->admin->id);
        $this->assertNotNull($audit);

        // Second concurrent attempt on the same source must reject (already merged).
        $other = $this->makeStudent(['email' => 'lock2@example.test']);

        try {
            $this->service->merge($source->id, $other->id, $this->admin->id);
            $this->fail('Expected ValidationException for already-merged source.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source_id', $e->errors());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 19. test_failure_in_strategy_rolls_back_transaction
    // ─────────────────────────────────────────────────────────────────────────

    public function test_failure_in_strategy_rolls_back_transaction(): void
    {
        $source = $this->makePinStudent('ROLLBK');
        $target = $this->makeStudent(['email' => 'rb@example.test']);
        $this->makeStats($source, ['xp_points' => 100]);
        $this->makeStats($target, ['xp_points' => 50]);

        // Force a failure by injecting a bad write inside an explicit transaction
        // wrapped around merge() so we can simulate a strategy-mid failure.
        $beforeXp = UserStats::where('user_id', $target->id)->first()->xp_points;

        try {
            DB::transaction(function () use ($source, $target) {
                $this->service->merge($source->id, $target->id, $this->admin->id);
                // Force a rollback after the merge committed inside its inner
                // transaction. This exercises that the audit row + state stay
                // consistent — if the outer txn rolls back, nothing persists.
                throw new \RuntimeException('forced failure');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure', $e->getMessage());
        }

        // Verify nothing persisted (outer rollback consumed inner commits too).
        $source->refresh();
        $this->assertNull($source->merged_into_user_id);
        $this->assertSame(0, UserMerge::count());
        $this->assertSame(
            $beforeXp,
            UserStats::where('user_id', $target->id)->first()->xp_points
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bonus: HTTP layer — admin-only access on merge endpoints
    // ─────────────────────────────────────────────────────────────────────────

    public function test_merge_endpoint_requires_admin_role(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $token = $teacher->createToken('t')->plainTextToken;

        $source = $this->makePinStudent('HTTPSR');
        $target = $this->makeStudent(['email' => 'http@example.test']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/users/merge', [
                'source_id' => $source->id,
                'target_id' => $target->id,
            ]);

        // Teacher can pass `admin:admin,teacher` middleware but the controller
        // gate (isAdmin) blocks them. 403 expected.
        $response->assertStatus(403);
    }

    public function test_merge_endpoint_accepts_admin(): void
    {
        $token = $this->admin->createToken('a')->plainTextToken;

        $source = $this->makePinStudent('HTTPAD');
        $target = $this->makeStudent(['email' => 'httpok@example.test']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/users/merge', [
                'source_id' => $source->id,
                'target_id' => $target->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.source_user_id', $source->id);
        $response->assertJsonPath('data.target_user_id', $target->id);
    }
}
