<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * BR-R84FVD: a teacher's schools could only be set from the school's side.
 *
 * The school_teacher pivot (BR-8QAWKP) has always been many-to-many, but the
 * only screen that wrote it was the school dialog, so creating a teacher
 * meant a second trip to the Schools tab — and nothing on the teacher list
 * said which school anyone belonged to. The team endpoints and the user edit
 * now read and write the same pivot, which is what these tests pin: both
 * sides must agree, and it stays an admin decision.
 */
class TeacherSchoolAffiliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function asAdmin(): static
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('a')->plainTextToken);
    }

    private function schoolIdsOf(User $user): array
    {
        return $user->schools()->pluck('schools.id')->sort()->values()->all();
    }

    // ── Team: create ────────────────────────────────────────────────────────

    public function test_a_new_teacher_can_be_filed_under_several_schools(): void
    {
        $a = School::create(['name' => 'ZŠ Komenského']);
        $b = School::create(['name' => 'ZŠ Palackého']);

        $response = $this->asAdmin()
            ->postJson('/api/admin/team', [
                'name' => 'Jana Nová',
                'email' => 'jana@skola.cz',
                'role' => 'teacher',
                'school_ids' => [$a->id, $b->id],
            ])
            ->assertStatus(201);

        $teacher = User::where('email', 'jana@skola.cz')->firstOrFail();

        $this->assertSame([$a->id, $b->id], $this->schoolIdsOf($teacher));
        $this->assertEqualsCanonicalizing(
            ['ZŠ Komenského', 'ZŠ Palackého'],
            array_column($response->json('data.schools'), 'name')
        );
    }

    public function test_a_school_is_optional_when_creating_a_teacher(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/team', [
                'name' => 'Bez Skoly',
                'email' => 'bez@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.schools', []);
    }

    public function test_an_unknown_school_is_rejected(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/team', [
                'name' => 'Jana Nová',
                'email' => 'jana@skola.cz',
                'role' => 'teacher',
                'school_ids' => [999],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_ids.0');

        $this->assertDatabaseMissing('users', ['email' => 'jana@skola.cz']);
    }

    // ── Team: list ──────────────────────────────────────────────────────────

    public function test_the_roster_lists_each_members_schools(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $rows = collect(
            $this->asAdmin()->getJson('/api/admin/team')->assertStatus(200)->json('data')
        )->keyBy('id');

        $this->assertSame(
            [['id' => $school->id, 'name' => 'ZŠ Komenského']],
            $rows[$teacher->id]['schools']
        );
    }

    // ── Team: edit ──────────────────────────────────────────────────────────

    public function test_editing_a_teacher_replaces_their_schools(): void
    {
        $a = School::create(['name' => 'A']);
        $b = School::create(['name' => 'B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $a->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['school_ids' => [$b->id]])
            ->assertStatus(200)
            ->assertJsonPath('data.schools.0.id', $b->id);

        $this->assertSame([$b->id], $this->schoolIdsOf($teacher));
    }

    public function test_an_edit_without_school_ids_leaves_them_alone(): void
    {
        $school = School::create(['name' => 'A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['name' => 'Přejmenovaný'])
            ->assertStatus(200);

        $this->assertSame([$school->id], $this->schoolIdsOf($teacher));
    }

    public function test_an_empty_list_unfiles_the_teacher(): void
    {
        $school = School::create(['name' => 'A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['school_ids' => []])
            ->assertStatus(200);

        $this->assertSame([], $this->schoolIdsOf($teacher));
    }

    public function test_the_change_is_audited(): void
    {
        $school = School::create(['name' => 'A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['school_ids' => [$school->id]])
            ->assertStatus(200);

        $log = AuditLog::where('action', 'user.schools_changed')->firstOrFail();

        $this->assertSame($teacher->id, (int) $log->target_id);
        $this->assertSame(['from' => [], 'to' => [$school->id]], $log->diff['school_ids']);
    }

    public function test_the_school_side_sees_the_same_assignment(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $admin = $this->asAdmin();
        $admin->putJson('/api/admin/team/' . $teacher->id, ['school_ids' => [$school->id]])
            ->assertStatus(200);

        $this->assertSame(
            [$teacher->id],
            array_column($admin->getJson('/api/admin/schools/' . $school->id)->json('data.teachers'), 'id')
        );
    }

    public function test_the_assignment_widens_the_teachers_classroom_list(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        Classroom::create(['name' => '7.C', 'school_id' => $school->id]);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, ['school_ids' => [$school->id]])
            ->assertStatus(200);

        app('auth')->forgetGuards();

        $names = collect(
            $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
                ->getJson('/api/admin/classrooms')
                ->json('data')
        )->pluck('name');

        $this->assertContains('7.C', $names);
    }

    public function test_demoting_to_student_does_not_file_them_under_a_school(): void
    {
        $school = School::create(['name' => 'A']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()
            ->putJson('/api/admin/team/' . $teacher->id, [
                'role' => 'student',
                'school_ids' => [$school->id],
            ])
            ->assertStatus(200);

        $this->assertSame([], $this->schoolIdsOf($teacher));
    }

    // ── User detail edit ────────────────────────────────────────────────────

    public function test_the_user_detail_reports_and_edits_a_teachers_schools(): void
    {
        $a = School::create(['name' => 'A']);
        $b = School::create(['name' => 'B']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $a->teachers()->attach($teacher->id);

        $admin = $this->asAdmin();

        $admin->getJson('/api/admin/users/' . $teacher->id)
            ->assertStatus(200)
            ->assertJsonPath('data.school_ids', [$a->id]);

        $admin->putJson('/api/admin/users/' . $teacher->id, ['school_ids' => [$a->id, $b->id]])
            ->assertStatus(200)
            ->assertJsonPath('data.school_ids', [$a->id, $b->id]);

        $this->assertSame([$a->id, $b->id], $this->schoolIdsOf($teacher));
    }

    public function test_promoting_a_student_can_file_them_in_the_same_save(): void
    {
        $school = School::create(['name' => 'A']);
        $student = User::factory()->create(['role' => 'student']);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $student->id, [
                'role' => 'teacher',
                'school_ids' => [$school->id],
            ])
            ->assertStatus(200);

        $this->assertSame([$school->id], $this->schoolIdsOf($student));
    }

    public function test_a_student_is_never_filed_under_a_school(): void
    {
        $school = School::create(['name' => 'A']);
        $student = User::factory()->create(['role' => 'student']);

        $this->asAdmin()
            ->putJson('/api/admin/users/' . $student->id, ['school_ids' => [$school->id]])
            ->assertStatus(200);

        $this->assertSame([], $this->schoolIdsOf($student));
    }

    public function test_a_teacher_cannot_file_anyone_under_a_school(): void
    {
        $school = School::create(['name' => 'A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $classroom = Classroom::create(['name' => '6.A']);
        $classroom->teachers()->attach($teacher->id);

        // A colleague promoted (or joined by class code) and sitting in the
        // teacher's class is on their roster — still not theirs to edit at
        // all since BR-BERW9B.
        $colleague = User::factory()->create(['role' => 'teacher']);
        $colleague->classrooms()->attach($classroom->id);

        $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
            ->putJson('/api/admin/users/' . $colleague->id, ['school_ids' => [$school->id]])
            ->assertStatus(403);

        $this->assertSame([], $this->schoolIdsOf($colleague));
    }
}
