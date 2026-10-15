<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-3JZWEY: in a teacher's administration the class detail must not offer
 * Smazat — deleting a class (and with it the placeholder pupils minted for
 * it) stays with the admin. Nor may a teacher's edit of a pupil touch their
 * role; the client saw the role picker and read it as "teachers can make
 * pupils admins".
 */
class TeacherAdminOnlyClassroomActionsTest extends TestCase
{
    use RefreshDatabase;

    private function teacherOf(Classroom $classroom): User
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom->teachers()->attach($teacher->id);

        return $teacher;
    }

    public function test_a_teacher_cannot_delete_a_class_they_teach(): void
    {
        $classroom = Classroom::create(['name' => '7.A']);
        $pupil = User::factory()->inClassroom($classroom)->create(['role' => 'student', 'email' => null]);
        $teacher = $this->teacherOf($classroom);

        $this->withToken($teacher->createToken('t')->plainTextToken)
            ->deleteJson("/api/admin/classrooms/{$classroom->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseHas('users', ['id' => $pupil->id]);
    }

    public function test_an_admin_still_deletes_the_class(): void
    {
        $classroom = Classroom::create(['name' => '7.A']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withToken($admin->createToken('a')->plainTextToken)
            ->deleteJson("/api/admin/classrooms/{$classroom->id}")
            ->assertOk();

        $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);
    }

    public function test_a_teacher_cannot_promote_their_pupil(): void
    {
        $classroom = Classroom::create(['name' => '7.A']);
        $pupil = User::factory()->inClassroom($classroom)->create(['role' => 'student']);
        $teacher = $this->teacherOf($classroom);

        $this->withToken($teacher->createToken('t')->plainTextToken)
            ->putJson("/api/admin/users/{$pupil->id}", ['role' => 'admin']);

        $this->assertSame('student', $pupil->fresh()->role);
    }
}
