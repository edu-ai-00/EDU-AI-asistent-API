<?php

namespace Tests\Feature;

use App\Models\AvatarItem;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\MiniGame;
use App\Models\MiniGamePlay;
use App\Models\News;
use App\Models\NewsUserRead;
use App\Models\PracticeCard;
use App\Models\PracticeReviewLog;
use App\Models\User;
use App\Models\UserAvatarItem;
use App\Models\UserCourse;
use App\Models\UserStats;
use App\Models\WorkHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-AT7X2T: per-student, per-day activity overview for admins and teachers,
 * assembled from the activity tables that already exist (heartbeats, ELO
 * interactions, lesson block timestamps, practice reviews, chats, news reads,
 * avatar purchases, mini-game plays, XP).
 */
class ActivityReportTest extends TestCase
{
    use RefreshDatabase;

    /** Local (Europe/Prague) day every "day one" record lands on. */
    private const DAY = '2026-07-09';

    protected function setUp(): void
    {
        parent::setUp();
        // Chat messages only carry created_at, so pin "now" to day one.
        Carbon::setTestNow(Carbon::parse('2026-07-09T10:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * One student with one of everything on day one, plus a lone news read
     * the day before so range filtering has something to drop.
     */
    private function seedStudent(?int $classroomId = null): User
    {
        $factory = User::factory();
        if ($classroomId !== null) {
            $factory = $factory->inClassroom($classroomId);
        }
        $student = $factory->create(['role' => 'student']);

        // Work time + courses: two heartbeats a minute apart.
        foreach (['10:00:00', '10:01:00'] as $i => $time) {
            WorkHeartbeat::create([
                'user_id' => $student->id,
                'client_uuid' => sprintf('%08d-0000-4000-8000-%012d', $student->id, $i),
                'course_id' => 'math',
                'occurred_at' => "2026-07-09T{$time}+02:00",
            ]);
        }

        // Lesson block answered (counts as a visited block) + a quiz block.
        EloInteraction::create([
            'user_id' => $student->id, 'block_id' => 'b1', 'course_id' => 'math',
            'source' => 'lesson', 'score' => 1.0, 'confirmed_at' => '2026-07-09T10:00:30+02:00',
        ]);
        EloInteraction::create([
            'user_id' => $student->id, 'block_id' => 'q1', 'course_id' => 'math',
            'source' => 'quiz', 'score' => 0.5, 'confirmed_at' => '2026-07-09T10:02:00+02:00',
        ]);

        // A text block the app only records via lesson block_timestamps.
        $course = Course::firstOrCreate(
            ['course_id' => 'math'],
            ['name' => 'Math', 'status' => 'published', 'data' => ['blocks' => []]],
        );
        UserCourse::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'in_progress',
            'progress_data' => ['lessons' => [
                ['lesson_id' => 'l1', 'block_timestamps' => [
                    'b2' => ['opened_at' => '2026-07-09T08:00:00Z'],
                    // Same block as the ELO row above — must not double count.
                    'b1' => ['opened_at' => '2026-07-09T07:59:00Z', 'confirmed_at' => '2026-07-09T08:00:30Z'],
                ]],
            ]],
        ]);

        // Practice (FSRS) review.
        $card = PracticeCard::create([
            'user_id' => $student->id, 'course_id' => 'math', 'lesson_id' => 'l1',
            'block_id' => 'b1', 'source_type' => 'exercise',
        ]);
        PracticeReviewLog::create([
            'card_id' => $card->id, 'user_id' => $student->id, 'rating' => 3,
            'shown_at' => '2026-07-09T11:00:00+02:00', 'reviewed_at' => '2026-07-09T11:00:10+02:00',
            'response_time_sec' => 10, 'repetition_number' => 1, 'stability_after' => 1.0,
            'difficulty_after' => 5.0, 'next_due_date' => '2026-07-10T11:00:10+02:00', 'interval_days' => 1,
        ]);

        // One chat with two student messages — one chat, not two.
        $session = ChatSession::create(['user_id' => $student->id, 'persona' => 'mentor']);
        ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => 'Ahoj']);
        ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'assistant', 'content' => 'Ahoj!']);
        ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => 'Pomoc']);

        // News: one on day one, one the day before.
        $news = News::factory()->count(2)->create();
        NewsUserRead::create(['news_id' => $news[0]->id, 'user_id' => $student->id, 'read_at' => '2026-07-09T12:00:00+02:00']);
        NewsUserRead::create(['news_id' => $news[1]->id, 'user_id' => $student->id, 'read_at' => '2026-07-08T12:00:00+02:00']);

        // Avatar purchase.
        $item = AvatarItem::firstOrCreate(
            ['code' => 'head_test'],
            ['name' => 'Test', 'slot' => 'head', 'layer' => 4, 'r2_path' => 'x', 'url' => 'https://x'],
        );
        UserAvatarItem::create([
            'user_id' => $student->id, 'avatar_item_id' => $item->id,
            'acquired_at' => '2026-07-09T13:00:00+02:00',
        ]);

        // Mini game.
        $game = MiniGame::factory()->create();
        MiniGamePlay::create([
            'mini_game_id' => $game->id, 'user_id' => $student->id,
            'played_at' => '2026-07-09T14:00:00+02:00', 'score' => 10,
        ]);

        UserStats::create(['user_id' => $student->id, 'level' => 2, 'xp_points' => 120]);

        return $student;
    }

    public function test_admin_gets_one_row_per_student_and_day_with_all_counters(): void
    {
        $student = $this->seedStudent();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $rows = $this->getJson('/api/admin/activity')->assertOk()->json('data');

        $this->assertCount(2, $rows, 'one row per active day');
        $this->assertSame(['2026-07-08', self::DAY], array_column($rows, 'day'));

        $dayOne = $rows[1];
        $this->assertSame($student->id, $dayOne['user_id']);
        $this->assertSame([], $dayOne['classroom_ids']);
        $this->assertGreaterThan(0, $dayOne['work_seconds']);
        $this->assertSame(1, $dayOne['courses_visited']);
        $this->assertSame(2, $dayOne['blocks_visited'], 'b1 (ELO + timestamps) and b2 (timestamps only)');
        $this->assertSame(1, $dayOne['practice_blocks']);
        $this->assertSame(1, $dayOne['quiz_blocks']);
        $this->assertSame(1, $dayOne['chats']);
        $this->assertSame(1, $dayOne['news_opened']);
        $this->assertSame(1, $dayOne['avatar_purchases']);
        $this->assertSame(1, $dayOne['games']);
        $this->assertSame(120, $dayOne['xp_points']);

        // The day before only had a news read: everything else is zero.
        $dayBefore = $rows[0];
        $this->assertSame(1, $dayBefore['news_opened']);
        $this->assertSame(0, $dayBefore['work_seconds']);
        $this->assertSame(0, $dayBefore['blocks_visited']);
        $this->assertSame(0, $dayBefore['chats']);
    }

    public function test_date_range_filters_days(): void
    {
        $this->seedStudent();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $rows = $this->getJson('/api/admin/activity?from=2026-07-09&to=2026-07-09')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(self::DAY, $rows[0]['day']);

        $this->getJson('/api/admin/activity?from=2026-07-10&to=2026-07-09')
            ->assertUnprocessable();
    }

    public function test_teacher_only_sees_own_classrooms(): void
    {
        $mine = Classroom::create(['name' => '5.A']);
        $theirs = Classroom::create(['name' => '5.B']);
        $own = $this->seedStudent($mine->id);
        $this->seedStudent($theirs->id);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->teachingClassrooms()->attach($mine->id);
        Sanctum::actingAs($teacher);

        $rows = $this->getJson('/api/admin/activity')->assertOk()->json('data');
        $this->assertSame([$own->id], array_values(array_unique(array_column($rows, 'user_id'))));

        $this->getJson("/api/admin/activity?classroom_id={$theirs->id}")->assertForbidden();
        $this->getJson("/api/admin/activity/export?classroom_id={$theirs->id}")->assertForbidden();
    }

    public function test_classroom_filter_narrows_admin_view(): void
    {
        $a = Classroom::create(['name' => '5.A']);
        $b = Classroom::create(['name' => '5.B']);
        $inA = $this->seedStudent($a->id);
        $this->seedStudent($b->id);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $rows = $this->getJson("/api/admin/activity?classroom_id={$a->id}")->assertOk()->json('data');

        $this->assertSame([$inA->id], array_values(array_unique(array_column($rows, 'user_id'))));
        $this->assertSame([$a->id], $rows[0]['classroom_ids']);
    }

    public function test_export_streams_tab_separated_csv_with_bom(): void
    {
        $student = $this->seedStudent();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $res = $this->get('/api/admin/activity/export?from=2026-07-09&to=2026-07-09')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));

        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines, 'header + one day');
        $this->assertStringStartsWith("\xEF\xBB\xBF\"ID žáka (interní)\"\t", $lines[0]);
        $this->assertStringContainsString('Počet bloků kvíz', $lines[0]);

        $cells = explode("\t", $lines[1]);
        $this->assertSame((string) $student->id, $cells[0]);
        $this->assertSame(self::DAY, $cells[3]);
        $this->assertSame('120', end($cells));
    }

    public function test_student_cannot_read_the_report(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->getJson('/api/admin/activity')->assertForbidden();
        $this->get('/api/admin/activity/export')->assertForbidden();
    }
}
