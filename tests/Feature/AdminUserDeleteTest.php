<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserProgress;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-FXWJ8D: next to Deaktivovat, an admin needs Smazat — remove a student
 * from the system together with their history, with no way back. Only an
 * admin may, and only a student: team members are taken off the team first.
 */
class AdminUserDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithToken(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return [$admin, $admin->createToken('admin-token', ['admin'])->plainTextToken];
    }

    public function test_admin_deletes_a_student_with_their_history(): void
    {
        [$admin, $adminToken] = $this->adminWithToken();
        $classroom = Classroom::create(['name' => '8.C']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Létající jelen']);
        $student->classrooms()->attach($classroom->id);
        $student->createToken('auth_token');
        UserProgress::create(['user_id' => $student->id, 'course_id' => 'c1', 'lesson_id' => 'l1']);
        QuizAttempt::create([
            'user_id' => $student->id, 'course_id' => 'c1',
            'total_questions' => 5, 'correct_answers' => 4, 'score_percent' => 80,
        ]);

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->deleteJson("/api/admin/users/{$student->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('user_progress', ['user_id' => $student->id]);
        $this->assertDatabaseMissing('quiz_attempts', ['user_id' => $student->id]);
        $this->assertDatabaseMissing('classroom_student', ['user_id' => $student->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $student->id,
            'tokenable_type' => User::class,
        ]);
        // The class itself stays; only the student left it.
        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.deleted',
            'actor_id' => $admin->id,
            'target_type' => 'user',
            'target_id' => $student->id,
        ]);
    }

    /**
     * A student who used the app as a guest before signing in is the target
     * of a user_merges row (absorbGuest), whose foreign keys RESTRICT the
     * delete. The merge history and the hollow guest profile go with them.
     */
    public function test_a_student_who_started_as_a_guest_can_be_deleted(): void
    {
        [, $adminToken] = $this->adminWithToken();
        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $student = User::factory()->create(['role' => 'student', 'email' => 'zak@example.test']);
        $merge = app(UserMergeService::class)->absorbGuest($guest, $student);
        $this->assertNotNull($merge);

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->deleteJson("/api/admin/users/{$student->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('users', ['id' => $guest->id]);
        $this->assertDatabaseMissing('user_merges', ['id' => $merge->id]);
    }

    public function test_a_teacher_cannot_delete(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom = Classroom::create(['name' => '8.C']);
        $classroom->teachers()->attach($teacher->id);
        $student = User::factory()->create(['role' => 'student']);
        $student->classrooms()->attach($classroom->id);

        $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('admin-token', ['admin'])->plainTextToken)
            ->deleteJson("/api/admin/users/{$student->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_team_members_cannot_be_deleted(): void
    {
        [$admin, $adminToken] = $this->adminWithToken();
        $teacher = User::factory()->create(['role' => 'teacher']);

        foreach ([$teacher, $admin] as $member) {
            $this->withHeader('Authorization', "Bearer {$adminToken}")
                ->deleteJson("/api/admin/users/{$member->id}")
                ->assertStatus(422);

            $this->assertDatabaseHas('users', ['id' => $member->id]);
        }
    }
}
