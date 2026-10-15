<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-8QAWKP: schools as organisational units.
 *
 * A school groups classrooms and teachers so a teacher can see that a
 * colleague's class exists — its name and who teaches it — without being able
 * to open it. The whole point of the feature is the gap between those two:
 * the list widens, access does not. These tests pin both halves, because the
 * failure mode that matters is the widening quietly leaking student data.
 */
class SchoolOrganisationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('t')->plainTextToken;
    }

    private function asUser(User $user)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token($user));
    }

    // ── Visibility ──────────────────────────────────────────────────────────

    public function test_teacher_sees_other_classes_in_the_same_school(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $colleague = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $mine = Classroom::create(['name' => '6.A', 'school_id' => $school->id]);
        $mine->teachers()->attach($teacher->id);

        $theirs = Classroom::create(['name' => '6.B', 'school_id' => $school->id]);
        $theirs->teachers()->attach($colleague->id);

        $rows = $this->asUser($teacher)
            ->getJson('/api/admin/classrooms')
            ->assertStatus(200)
            ->json('data');

        $byName = collect($rows)->keyBy('name');

        $this->assertTrue($byName->has('6.B'), 'School-mate class should be listed.');
        $this->assertTrue($byName['6.A']['is_own']);
        $this->assertFalse($byName['6.B']['is_own']);

        // Name and teachers, and nothing beyond it.
        $this->assertSame(
            [$colleague->name],
            array_column($byName['6.B']['teachers'], 'name')
        );
        $this->assertSame('ZŠ Komenského', $byName['6.B']['school']['name']);
    }

    public function test_teacher_does_not_see_classes_from_another_school(): void
    {
        $mySchool = School::create(['name' => 'ZŠ Komenského']);
        $otherSchool = School::create(['name' => 'ZŠ Palackého']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $mySchool->teachers()->attach($teacher->id);

        Classroom::create(['name' => 'Cizí', 'school_id' => $otherSchool->id]);

        $names = collect($this->asUser($teacher)->getJson('/api/admin/classrooms')->json('data'))
            ->pluck('name');

        $this->assertNotContains('Cizí', $names);
    }

    public function test_teacher_in_several_schools_sees_classes_from_all_of_them(): void
    {
        $first = School::create(['name' => 'ZŠ Komenského']);
        $second = School::create(['name' => 'ZŠ Palackého']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->schools()->attach([$first->id, $second->id]);

        Classroom::create(['name' => 'Prvni', 'school_id' => $first->id]);
        Classroom::create(['name' => 'Druha', 'school_id' => $second->id]);

        $names = collect($this->asUser($teacher)->getJson('/api/admin/classrooms')->json('data'))
            ->pluck('name');

        $this->assertContains('Prvni', $names);
        $this->assertContains('Druha', $names);
    }

    public function test_classes_without_a_school_stay_invisible_to_other_teachers(): void
    {
        // The migration leaves every existing classroom unfiled; nobody's view
        // may widen until an admin actually files one.
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        Classroom::create(['name' => 'Nezarazena']);

        $names = collect($this->asUser($teacher)->getJson('/api/admin/classrooms')->json('data'))
            ->pluck('name');

        $this->assertNotContains('Nezarazena', $names);
    }

    // ── Access stops at the list ────────────────────────────────────────────

    public function test_teacher_cannot_open_a_school_mates_class(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $colleague = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $theirs = Classroom::create(['name' => '6.B', 'school_id' => $school->id]);
        $theirs->teachers()->attach($colleague->id);

        $this->asUser($teacher)
            ->getJson('/api/admin/classrooms/' . $theirs->id)
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_own_classroom');
    }

    public function test_teacher_cannot_read_a_school_mates_student(): void
    {
        // The sharpest edge: a school-mate's class now shows up, so its
        // student ids become guessable. School membership must not open them.
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $colleague = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $theirs = Classroom::create(['name' => '6.B', 'school_id' => $school->id]);
        $theirs->teachers()->attach($colleague->id);
        $student = User::factory()->inClassroom($theirs)->create();

        $this->assertFalse($teacher->teachesStudent($student->id));

        $this->asUser($teacher)
            ->getJson('/api/admin/progress/user/' . $student->id)
            ->assertStatus(403);
    }

    public function test_teacher_cannot_modify_a_school_mates_class(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $theirs = Classroom::create(['name' => '6.B', 'school_id' => $school->id]);
        $theirs->teachers()->attach(User::factory()->create(['role' => 'teacher'])->id);

        $this->asUser($teacher)
            ->putJson('/api/admin/classrooms/' . $theirs->id, ['name' => 'Prejmenovana'])
            ->assertStatus(403);

        $this->assertSame('6.B', $theirs->fresh()->name);
    }

    // ── Admin-only management ───────────────────────────────────────────────

    public function test_admin_creates_a_school_and_assigns_teachers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $response = $this->asUser($admin)
            ->postJson('/api/admin/schools', [
                'name' => 'ZŠ Komenského',
                'address' => 'Komenského 1, Praha',
                'identifier' => '600001234',
                'teacher_ids' => [$teacher->id],
            ])
            ->assertStatus(201);

        $school = School::findOrFail($response->json('data.id'));

        $this->assertSame('ZŠ Komenského', $school->name);
        $this->assertSame('600001234', $school->identifier);
        $this->assertSame([$teacher->id], $school->teachers()->pluck('users.id')->all());
    }

    public function test_teacher_cannot_manage_schools(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $school = School::create(['name' => 'ZŠ Komenského']);

        $this->asUser($teacher)->getJson('/api/admin/schools')->assertStatus(403);
        $this->asUser($teacher)
            ->postJson('/api/admin/schools', ['name' => 'Moje'])
            ->assertStatus(403);
        $this->asUser($teacher)
            ->putJson('/api/admin/schools/' . $school->id, ['name' => 'Prejmenovana'])
            ->assertStatus(403);
    }

    public function test_students_cannot_be_assigned_to_a_school(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->asUser($admin)
            ->postJson('/api/admin/schools', [
                'name' => 'ZŠ Komenského',
                'teacher_ids' => [$student->id],
            ])
            ->assertStatus(201);

        $school = School::findOrFail($response->json('data.id'));

        $this->assertSame([], $school->teachers()->pluck('users.id')->all());
    }

    public function test_teacher_cannot_file_their_class_under_a_school(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $mine = Classroom::create(['name' => '6.A']);
        $mine->teachers()->attach($teacher->id);

        $this->asUser($teacher)
            ->putJson('/api/admin/classrooms/' . $mine->id, [
                'name' => '6.A',
                'school_id' => $school->id,
            ])
            ->assertStatus(200);

        $this->assertNull($mine->fresh()->school_id);
    }

    public function test_admin_creates_a_class_filed_under_a_school(): void
    {
        // BR-BERMY7: the new-class form had no school picker, so every class
        // an admin created came out unfiled.
        $admin = User::factory()->create(['role' => 'admin']);
        $school = School::create(['name' => 'ZŠ Komenského']);

        $response = $this->asUser($admin)
            ->postJson('/api/admin/classrooms', [
                'name' => '6.A',
                'school_id' => $school->id,
                'students' => [['name' => 'Žák 1', 'login_code' => 'BRM7A1']],
            ])
            ->assertStatus(201);

        $classroom = Classroom::findOrFail($response->json('data.id'));

        $this->assertSame($school->id, $classroom->school_id);
    }

    public function test_teacher_cannot_file_a_new_class_under_a_school(): void
    {
        // Same rule as update(): filing decides who else sees the class.
        $school = School::create(['name' => 'ZŠ Komenského']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $response = $this->asUser($teacher)
            ->postJson('/api/admin/classrooms', [
                'name' => '6.A',
                'school_id' => $school->id,
                'students' => [['name' => 'Žák 1', 'login_code' => 'BRM7T1']],
            ])
            ->assertStatus(201);

        $this->assertNull(Classroom::findOrFail($response->json('data.id'))->school_id);
    }

    public function test_admin_files_classes_under_a_school(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $school = School::create(['name' => 'ZŠ Komenského']);

        $first = Classroom::create(['name' => '6.A']);
        $second = Classroom::create(['name' => '6.B']);

        $this->asUser($admin)
            ->putJson('/api/admin/schools/' . $school->id . '/classrooms', [
                'classroom_ids' => [$first->id, $second->id],
            ])
            ->assertStatus(200);

        $this->assertSame($school->id, $first->fresh()->school_id);
        $this->assertSame($school->id, $second->fresh()->school_id);

        // A sync, not an append: dropping one unfiles it rather than deleting.
        $this->asUser($admin)
            ->putJson('/api/admin/schools/' . $school->id . '/classrooms', [
                'classroom_ids' => [$first->id],
            ])
            ->assertStatus(200);

        $this->assertSame($school->id, $first->fresh()->school_id);
        $this->assertNull($second->fresh()->school_id);
        $this->assertNotNull(Classroom::find($second->id));
    }

    public function test_deleting_a_school_keeps_its_classrooms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $school = School::create(['name' => 'ZŠ Komenského']);
        $classroom = Classroom::create(['name' => '6.A', 'school_id' => $school->id]);

        $this->asUser($admin)
            ->deleteJson('/api/admin/schools/' . $school->id)
            ->assertStatus(200);

        $this->assertNotNull(Classroom::find($classroom->id));
        $this->assertNull($classroom->fresh()->school_id);
    }
}
