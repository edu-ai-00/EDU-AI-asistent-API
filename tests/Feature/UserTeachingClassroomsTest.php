<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-QKCBCT: "Upravit uživatele" files a teacher or admin under schools and
 * then into the classes of those schools.
 *
 * The dialog's only class list used to write classroom_student for every
 * role, so ticking 9.A for a teacher enrolled them in 9.A as a pupil instead
 * of making them its teacher. A team member's classes are now the ones they
 * teach — the classroom_teacher pivot the class dialog's teacher roster
 * writes — and these tests pin that both sides agree, that a pupil's own
 * classes are untouched by it, and that it stays an admin decision.
 */
class UserTeachingClassroomsTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('a')->plainTextToken);
    }

    private function teachingIdsOf(User $user): array
    {
        return $user->teachingClassrooms()->pluck('classrooms.id')->sort()->values()->all();
    }

    private function memberIdsOf(User $user): array
    {
        return $user->classrooms()->pluck('classrooms.id')->sort()->values()->all();
    }

    public function test_the_user_detail_reports_the_classes_a_teacher_teaches(): void
    {
        $taught = Classroom::create(['name' => '9.A']);
        Classroom::create(['name' => '9.B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $taught->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->getJson('/api/admin/users/' . $teacher->id)
            ->assertStatus(200)
            ->assertJsonPath('data.teaching_classroom_ids', [$taught->id])
            ->assertJsonPath('data.classroom_ids', []);
    }

    public function test_editing_a_teacher_makes_them_teach_the_classes_not_sit_in_them(): void
    {
        $a = Classroom::create(['name' => '9.A']);
        $b = Classroom::create(['name' => '9.B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $a->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $teacher->id, ['teaching_classroom_ids' => [$b->id]])
            ->assertStatus(200)
            ->assertJsonPath('data.teaching_classroom_ids', [$b->id]);

        $this->assertSame([$b->id], $this->teachingIdsOf($teacher));
        $this->assertSame([], $this->memberIdsOf($teacher));
    }

    public function test_the_class_side_sees_the_same_teacher(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $admin = $this->asAdmin();
        $admin->putJson('/api/admin/users/' . $teacher->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(200);

        $this->assertSame(
            [$teacher->id],
            array_column($admin->getJson('/api/admin/classrooms/' . $classroom->id)->json('data.teachers'), 'id')
        );
    }

    public function test_an_admin_can_be_given_classes_too(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $other = User::factory()->create(['role' => 'admin']);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $other->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(200);

        $this->assertSame([$classroom->id], $this->teachingIdsOf($other));
    }

    public function test_an_edit_without_the_field_leaves_the_classes_alone(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $teacher->id, ['name' => 'Přejmenovaný', 'classroom_ids' => []])
            ->assertStatus(200);

        $this->assertSame([$classroom->id], $this->teachingIdsOf($teacher));
    }

    public function test_the_change_is_audited(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $teacher->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(200);

        $log = AuditLog::where('action', 'user.teaching_classrooms_changed')->firstOrFail();

        $this->assertSame($teacher->id, (int) $log->target_id);
        $this->assertSame(['from' => [], 'to' => [$classroom->id]], $log->diff['classroom_ids']);
    }

    public function test_promoting_a_student_can_hand_them_classes_in_the_same_save(): void
    {
        $sitsIn = Classroom::create(['name' => '8.C']);
        $teaches = Classroom::create(['name' => '6.A']);
        $student = User::factory()->create(['role' => 'student']);
        $student->classrooms()->attach($sitsIn->id);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $student->id, [
                'role' => 'teacher',
                'teaching_classroom_ids' => [$teaches->id],
            ])
            ->assertStatus(200);

        $this->assertSame([$teaches->id], $this->teachingIdsOf($student));
        // Their seat in 8.C is classroom_student, which this field never touches.
        $this->assertSame([$sitsIn->id], $this->memberIdsOf($student));
    }

    public function test_a_student_never_becomes_a_teacher_of_a_class(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $student = User::factory()->create(['role' => 'student']);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $student->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(200);

        $this->assertSame([], $this->teachingIdsOf($student));
    }

    public function test_a_self_service_class_cannot_be_handed_a_teacher(): void
    {
        $family = Classroom::create(['name' => 'Rodina', 'type' => 'self_service']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $teacher->id, ['teaching_classroom_ids' => [$family->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('teaching_classroom_ids.0');

        $this->assertSame([], $this->teachingIdsOf($teacher));
    }

    public function test_a_teacher_cannot_hand_out_classes(): void
    {
        $own = Classroom::create(['name' => '6.A']);
        $other = Classroom::create(['name' => '6.B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $own->teachers()->attach($teacher->id);

        // A colleague promoted (or joined by class code) and sitting in the
        // teacher's class is on their roster — still not theirs to edit at
        // all since BR-BERW9B.
        $colleague = User::factory()->create(['role' => 'teacher']);
        $colleague->classrooms()->attach($own->id);

        $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
            ->putJson('/api/admin/users/' . $colleague->id, ['teaching_classroom_ids' => [$other->id]])
            ->assertStatus(403);

        $this->assertSame([], $this->teachingIdsOf($colleague));
    }
}
