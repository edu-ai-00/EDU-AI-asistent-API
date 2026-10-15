<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-38FYMX: who teaches a class is an admin decision.
 *
 * A teacher may create and rename their own classes, but `teacher_ids` used
 * to be honoured from whoever sent it. That let a teacher hand their class to
 * someone else, attach themselves to a colleague's roster, or — because the
 * field is a sync, not an append — drop themselves and lose access to their
 * own students with no way back short of an admin.
 */
class ClassroomTeacherRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_creating_a_class_gets_exactly_themselves(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $colleague = User::factory()->create(['role' => 'teacher']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
            ->postJson('/api/admin/classrooms', [
                'name' => '6.C',
                'students' => [['name' => 'Žák', 'login_code' => 'ABC123']],
                'teacher_ids' => [$colleague->id],
            ])
            ->assertStatus(201);

        $classroom = Classroom::findOrFail($response->json('data.id'));

        $this->assertSame([$teacher->id], $classroom->teachers()->pluck('users.id')->all());
    }

    public function test_teacher_cannot_change_the_roster_of_their_own_class(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $colleague = User::factory()->create(['role' => 'teacher']);

        $classroom = Classroom::create(['name' => '6.C']);
        $classroom->teachers()->attach($teacher->id);

        $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
            ->putJson('/api/admin/classrooms/' . $classroom->id, [
                'name' => '6.D',
                'teacher_ids' => [$colleague->id],
            ])
            ->assertStatus(200);

        // The rename lands, the roster does not.
        $this->assertSame('6.D', $classroom->fresh()->name);
        $this->assertSame([$teacher->id], $classroom->teachers()->pluck('users.id')->all());
    }

    public function test_admin_assigns_teachers_to_a_class(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $classroom = Classroom::create(['name' => '6.C']);

        $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('a')->plainTextToken)
            ->putJson('/api/admin/classrooms/' . $classroom->id, [
                'teacher_ids' => [$teacher->id],
            ])
            ->assertStatus(200);

        $this->assertSame([$teacher->id], $classroom->teachers()->pluck('users.id')->all());
    }
}
