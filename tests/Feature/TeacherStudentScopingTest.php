<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\OpenAnswerEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BR-38FYMX: the per-student admin reports took a user id straight from the
 * route and answered for anyone.
 *
 * Every other teacher-facing endpoint is scoped — classrooms by the pivot,
 * course reports by TeacherCourseAccess, the student list and detail by
 * AdminUserController — but /admin/progress/user/{id} and its three siblings
 * are keyed by student rather than by course, so route access alone let a
 * teacher read the progress, quiz attempts, graded free-text answers and
 * work-time of any pupil in the school. The admin UI never asks for them
 * (UserDetailPanel hides those tabs from teachers), which is exactly why it
 * went unnoticed: the hole was only reachable by calling the API directly.
 *
 * TeacherStudentAccess closes it; the photo endpoint is keyed by evaluation
 * id instead of student id, so it checks ownership in the controller.
 */
class TeacherStudentScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $ownStudent;
    private User $otherStudent;
    private string $teacherToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $mine = Classroom::create(['name' => '5.A']);
        $theirs = Classroom::create(['name' => '5.B']);

        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->teacher->teachingClassrooms()->attach($mine->id);

        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $otherTeacher->teachingClassrooms()->attach($theirs->id);

        $this->ownStudent = User::factory()->inClassroom($mine)->create();
        $this->otherStudent = User::factory()->inClassroom($theirs)->create();

        $this->teacherToken = $this->teacher->createToken('t')->plainTextToken;
        $this->adminToken = User::factory()
            ->create(['role' => 'admin'])
            ->createToken('a')
            ->plainTextToken;
    }

    /** @return array<int, string> */
    public static function perStudentEndpoints(): array
    {
        return [
            'progress' => ['/api/admin/progress/user/%d'],
            'quiz attempts' => ['/api/admin/quiz-attempts/user/%d'],
            'open answers' => ['/api/admin/open-answers/user/%d'],
            'work time' => ['/api/admin/users/%d/work-time'],
            // BR-J9D2J9: the student detail opens these two on every render —
            // admin-only answered 403 and the admin UI logged the teacher out.
            'elo profile' => ['/api/admin/elo/profile/%d'],
            'elo interactions' => ['/api/admin/elo/interactions/user/%d'],
        ];
    }

    #[DataProvider('perStudentEndpoints')]
    public function test_teacher_cannot_read_a_student_from_another_classroom(string $path): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->teacherToken)
            ->getJson(sprintf($path, $this->otherStudent->id))
            ->assertStatus(403);
    }

    #[DataProvider('perStudentEndpoints')]
    public function test_teacher_can_read_their_own_student(string $path): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->teacherToken)
            ->getJson(sprintf($path, $this->ownStudent->id))
            ->assertStatus(200);
    }

    #[DataProvider('perStudentEndpoints')]
    public function test_admin_still_reaches_every_student(string $path): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson(sprintf($path, $this->otherStudent->id))
            ->assertStatus(200);
    }

    /**
     * BR-J9D2J9: everything the admin's student detail requests on open has to
     * answer a teacher, or the admin client reads the 403 as a lost session and
     * bounces them to /login.
     */
    public function test_teacher_can_open_the_whole_student_detail(): void
    {
        $paths = [
            '/api/admin/users/%d',
            '/api/admin/elo/profile/%d',
            '/api/admin/elo/interactions/user/%d',
        ];

        foreach ($paths as $path) {
            $this->withHeader('Authorization', 'Bearer ' . $this->teacherToken)
                ->getJson(sprintf($path, $this->ownStudent->id))
                ->assertStatus(200);
        }
    }

    public function test_teacher_without_any_classroom_is_denied(): void
    {
        $stray = User::factory()->create(['role' => 'teacher']);
        $token = $stray->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/progress/user/' . $this->ownStudent->id)
            ->assertStatus(403);
    }

    public function test_a_student_with_no_classroom_is_nobodys_pupil(): void
    {
        $orphan = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->teacherToken)
            ->getJson('/api/admin/progress/user/' . $orphan->id)
            ->assertStatus(403);
    }

    public function test_teacher_cannot_open_another_classrooms_answer_photo(): void
    {
        $evaluation = OpenAnswerEvaluation::create([
            'user_id' => $this->otherStudent->id,
            'client_uuid' => 'e1b6f2d4-0000-4000-8000-000000000001',
            'course_id' => 'kurz-1',
            'lesson_id' => 'lekce-1',
            'block_id' => 'blok-1',
            'answer_text' => 'ctyri',
            'answered_at' => now(),
            'photo_path' => 'answer-photos/' . $this->otherStudent->id . '/x.jpg',
        ]);

        // 404, not 403: a teacher should not learn that someone else's photo
        // exists, and the endpoint already answers 404 for a missing row.
        $this->withHeader('Authorization', 'Bearer ' . $this->teacherToken)
            ->getJson('/api/admin/open-answers/' . $evaluation->id . '/photo')
            ->assertStatus(404);
    }
}
