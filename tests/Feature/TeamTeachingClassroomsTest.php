<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-6W6R5Y: "Upravit člena týmu" never said which classes the teacher has.
 *
 * The classes a team member teaches have lived on classroom_teacher since
 * BR-QKCBCT, but only "Upravit uživatele" ever read or wrote them — the team
 * roster on the Učitelé tab carried nothing but the schools, so an admin
 * editing a teacher there could not see their classes at all. The roster now
 * reports them and takes the same full replacement the user dialog takes.
 */
class TeamTeachingClassroomsTest extends TestCase
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

    public function test_the_roster_reports_the_classes_a_member_teaches(): void
    {
        $taught = Classroom::create(['name' => '9.A']);
        Classroom::create(['name' => '9.B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $taught->teachers()->attach($teacher->id);

        $roster = $this->asAdmin()
            ->getJson('/api/admin/team')
            ->assertStatus(200)
            ->json('data');

        $row = collect($roster)->firstWhere('id', $teacher->id);

        $this->assertSame([$taught->id], $row['teaching_classroom_ids']);
    }

    public function test_a_member_with_no_classes_reports_an_empty_list(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $roster = $this->asAdmin()->getJson('/api/admin/team')->json('data');

        $this->assertSame([], collect($roster)->firstWhere('id', $teacher->id)['teaching_classroom_ids']);
    }

    public function test_the_dialog_can_replace_the_classes(): void
    {
        $a = Classroom::create(['name' => '9.A']);
        $b = Classroom::create(['name' => '9.B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $a->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['teaching_classroom_ids' => [$b->id]])
            ->assertStatus(200)
            ->assertJsonPath('data.teaching_classroom_ids', [$b->id]);

        $this->assertSame([$b->id], $this->teachingIdsOf($teacher));
    }

    public function test_the_class_side_sees_the_same_teacher(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $admin = $this->asAdmin();
        $admin->putJson('/api/admin/team/' . $teacher->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(200);

        $this->assertSame(
            [$teacher->id],
            array_column($admin->getJson('/api/admin/classrooms/' . $classroom->id)->json('data.teachers'), 'id')
        );
    }

    public function test_an_edit_without_the_field_leaves_the_classes_alone(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['name' => 'Přejmenovaná'])
            ->assertStatus(200);

        $this->assertSame([$classroom->id], $this->teachingIdsOf($teacher));
    }

    public function test_schools_and_classes_can_move_in_one_save(): void
    {
        $school = School::create(['name' => 'ZŠ Dukla']);
        $classroom = Classroom::create(['name' => '9.A', 'school_id' => $school->id]);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, [
                'school_ids' => [$school->id],
                'teaching_classroom_ids' => [$classroom->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.schools.0.id', $school->id)
            ->assertJsonPath('data.teaching_classroom_ids', [$classroom->id]);

        $this->assertSame([$classroom->id], $this->teachingIdsOf($teacher));
    }

    public function test_demoting_to_student_in_the_same_save_leaves_them_teaching_nothing(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, [
                'role' => 'student',
                'teaching_classroom_ids' => [$classroom->id],
            ])
            ->assertStatus(200);

        $this->assertSame([], $this->teachingIdsOf($teacher));
    }

    public function test_a_self_service_class_cannot_be_handed_a_teacher(): void
    {
        $family = Classroom::create(['name' => 'Rodina', 'type' => 'self_service']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['teaching_classroom_ids' => [$family->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('teaching_classroom_ids.0');

        $this->assertSame([], $this->teachingIdsOf($teacher));
    }

    public function test_the_change_is_audited(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(200);

        $log = AuditLog::where('action', 'user.teaching_classrooms_changed')->firstOrFail();

        $this->assertSame($teacher->id, (int) $log->target_id);
        $this->assertSame(['from' => [], 'to' => [$classroom->id]], $log->diff['classroom_ids']);
    }

    public function test_only_an_admin_may_hand_out_classes(): void
    {
        $classroom = Classroom::create(['name' => '9.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $colleague = User::factory()->create(['role' => 'teacher']);

        // Rule 4.4 — the endpoint is admin-only, so a teacher never reaches it.
        $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
            ->putJson('/api/admin/team/' . $colleague->id, ['teaching_classroom_ids' => [$classroom->id]])
            ->assertStatus(403);

        $this->assertSame([], $this->teachingIdsOf($colleague));
    }
}
