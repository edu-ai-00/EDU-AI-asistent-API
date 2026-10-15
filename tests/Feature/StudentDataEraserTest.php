<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ContentFeedback;
use App\Models\Course;
use App\Models\DebugReport;
use App\Models\EloInteraction;
use App\Models\News;
use App\Models\NewsUserRead;
use App\Models\PracticeCard;
use App\Models\PracticeReviewLog;
use App\Models\QuizAttempt;
use App\Models\SkillEventLog;
use App\Models\StudentFsrsProfile;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use App\Models\UserProgress;
use App\Models\UserStats;
use App\Models\UserTheme;
use App\Models\WorkHeartbeat;
use App\Services\StudentDataEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BR-8FDF37 I2: StudentDataEraser's docblock claims a specific set of tables
 * cascade on user_id and therefore need no help from this class. That claim
 * is exactly the kind of thing a later migration change can quietly falsify
 * — a prose comment doesn't notice when a cascadeOnDelete() is dropped or a
 * new user-owned table is added without one. This test makes the claim
 * machine-checked: it seeds one row per table the docblock lists, erases the
 * user, and asserts every single one is gone.
 */
class StudentDataEraserTest extends TestCase
{
    use RefreshDatabase;

    public function test_erasing_a_child_cascades_across_every_owned_table(): void
    {
        $child = User::factory()->create([
            'role' => 'student',
            'is_guest' => false,
            'is_minor' => true,
            'email' => null,
            'login_code_hash' => str_repeat('c', 64),
        ]);

        $course = Course::create([
            'course_id' => 'eraser-course',
            'name' => 'Eraser Course',
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ]);

        $news = News::create([
            'title' => 'Test news',
            'perex' => 'perex',
            'body' => 'body',
        ]);

        UserCourse::create(['user_id' => $child->id, 'course_id' => $course->id]);
        UserStats::create(['user_id' => $child->id, 'level' => 1, 'xp_points' => 10]);
        ContentFeedback::create([
            'user_id' => $child->id, 'course_id' => 'eraser-course',
            'block_id' => 'b1', 'type' => 'like',
        ]);
        UserProgress::create([
            'user_id' => $child->id, 'course_id' => 'eraser-course', 'lesson_id' => 'l1',
        ]);
        QuizAttempt::create([
            'user_id' => $child->id, 'course_id' => 'eraser-course',
            'total_questions' => 5, 'correct_answers' => 4, 'score_percent' => 80,
        ]);
        Bookmark::create([
            'user_id' => $child->id, 'course_id' => 'eraser-course',
            'lesson_id' => 'l1', 'block_id' => 'b1',
        ]);
        UserEloProfile::create(['user_id' => $child->id, 'profil_elo' => [], 'profil_pocet' => []]);
        EloInteraction::create([
            'user_id' => $child->id, 'block_id' => 'b1', 'course_id' => 'eraser-course', 'score' => 1.0,
        ]);
        UserAchievement::create(['user_id' => $child->id, 'achievement_id' => 'a1', 'earned_at' => now()]);
        $chatSession = ChatSession::create(['user_id' => $child->id, 'persona' => 'tutor']);
        ChatMessage::create([
            'chat_session_id' => $chatSession->id, 'role' => 'user', 'content' => 'ahoj',
        ]);
        UserTheme::create(['user_id' => $child->id, 'name' => 'Dark', 'theme_config' => []]);
        $practiceCard = PracticeCard::create([
            'user_id' => $child->id, 'course_id' => 'eraser-course',
            'lesson_id' => 'l1', 'block_id' => 'b1', 'source_type' => 'quiz',
        ]);
        PracticeReviewLog::create([
            'card_id' => $practiceCard->id, 'user_id' => $child->id, 'rating' => 3,
            'shown_at' => now(), 'reviewed_at' => now(), 'response_time_sec' => 5,
            'repetition_number' => 1, 'stability_after' => 1.0, 'difficulty_after' => 5.0,
            'next_due_date' => now()->addDay(), 'interval_days' => 1,
        ]);
        SkillEventLog::create(['student_id' => $child->id, 'score' => 1.0]);
        StudentFsrsProfile::create(['user_id' => $child->id]);
        NewsUserRead::create(['news_id' => $news->id, 'user_id' => $child->id, 'read_at' => now()]);
        WorkHeartbeat::create([
            'user_id' => $child->id, 'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'course_id' => 'eraser-course', 'occurred_at' => now(),
        ]);
        DebugReport::create([
            'user_id' => $child->id, 'app_version' => '1.0', 'platform' => 'web',
            'report_data' => ['note' => 'test'],
        ]);
        $child->createToken('t');

        app(StudentDataEraser::class)->erase($child->fresh());

        $this->assertDatabaseMissing('users', ['id' => $child->id]);
        $this->assertDatabaseMissing('user_courses', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('user_stats', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('content_feedback', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('user_progress', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('quiz_attempts', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('bookmarks', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('user_elo_profiles', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('elo_interactions', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('user_achievements', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('chat_sessions', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('user_themes', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('practice_cards', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('practice_review_logs', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('skill_event_logs', ['student_id' => $child->id]);
        $this->assertDatabaseMissing('student_fsrs_profiles', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('news_user_reads', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('work_heartbeats', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('debug_reports', ['user_id' => $child->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $child->id,
            'tokenable_type' => User::class,
        ]);

        // The course and news rows themselves are shared content, not the
        // child's own data — they must survive.
        $this->assertDatabaseHas('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('news', ['id' => $news->id]);

        // chat_messages cascades transitively via chat_sessions.chat_session_id,
        // not directly via user_id — verified separately since it has no
        // user_id column of its own to assert against.
        $this->assertSame(
            0,
            DB::table('chat_messages')->where('chat_session_id', $chatSession->id)->count(),
        );
    }
}
