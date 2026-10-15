<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-7UPBD7: a student who renamed themselves vanished from their place in
 * the class roster.
 *
 * The roster came back in whatever order the database produced the joined
 * rows. On Postgres an UPDATE writes a new row version at the end of the
 * heap, so the moment a student changed their nickname in the app their row
 * dropped from its slot to the bottom of the class list — under a name the
 * teacher had never seen. The progress was stored; the teacher just looked
 * for it in the row where the student used to be and found nothing.
 *
 * The roster is now ordered by when the student was put in the class
 * (classroom_student.id), which is also the order of the printed code list.
 */
class ClassroomRosterOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_lists_students_in_the_order_they_joined_the_class(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second, $third] = User::factory()->count(3)->create();

        $classroom = Classroom::create(['name' => '6.A']);
        // Deliberately not id order: membership order is what the roster
        // follows, whatever the user ids or the join plan would suggest.
        $classroom->students()->attach($third->id);
        $classroom->students()->attach($first->id);
        $classroom->students()->attach($second->id);

        $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('a')->plainTextToken)
            ->getJson('/api/admin/classrooms/' . $classroom->id)
            ->assertOk()
            ->assertJsonPath('data.students.*.id', [$third->id, $first->id, $second->id]);
    }

    public function test_student_keeps_their_row_after_renaming_themselves(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $classroom = Classroom::create(['name' => '6.A']);
        $students = collect(['Odvážný kolibřík', 'Divoký vydra', 'Tichý panda', 'Rychlý vlk'])
            ->map(fn (string $name) => User::factory()->create(['name' => $name, 'email' => null]));
        foreach ($students as $student) {
            $classroom->students()->attach($student->id);
        }

        // The student signs in with their code and changes the nickname.
        $renamed = $students[1];
        $this->withHeader('Authorization', 'Bearer ' . $renamed->createToken('s')->plainTextToken)
            ->putJson('/api/user/profile', ['name' => 'Divoký Mario'])
            ->assertOk();
        app('auth')->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('a')->plainTextToken)
            ->getJson('/api/admin/classrooms/' . $classroom->id)
            ->assertOk();

        $this->assertSame($students->pluck('id')->all(), $response->json('data.students.*.id'));
        $this->assertSame('Divoký Mario', $response->json('data.students.1.name'));
    }

    public function test_class_created_with_students_returns_them_in_the_submitted_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeader('Authorization', 'Bearer ' . $admin->createToken('a')->plainTextToken)
            ->postJson('/api/admin/classrooms', [
                'name' => '6.A',
                'students' => [
                    ['name' => 'Odvážný kolibřík', 'login_code' => 'VTRCBC'],
                    ['name' => 'Divoký vydra', 'login_code' => 'EUY8DP'],
                    ['name' => 'Létající vlk', 'login_code' => 'GN79DZ'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.students.*.login_code', ['VTRCBC', 'EUY8DP', 'GN79DZ']);
    }
}
