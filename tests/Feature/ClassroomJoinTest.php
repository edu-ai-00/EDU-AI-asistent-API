<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use App\Models\UserCourse;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-3V2P22: an existing account joins a class by typing the student code
 * the teacher handed out, and a student may be in several classes at once.
 *
 * A student code is the login code of a placeholder account the teacher
 * minted on the roster, so joining = merging that placeholder into the
 * caller's account (UserMergeService) and moving its classroom membership
 * (classroom_student pivot) across.
 */
class ClassroomJoinTest extends TestCase
{
    use RefreshDatabase;

    private function mintPlaceholder(Classroom $classroom, string $code, string $name = 'Jana Nováková'): User
    {
        $placeholder = User::create([
            'name' => $name,
            'login_code' => $code,
            'login_code_hash' => StudentCodes::hash($code),
        ]);
        $placeholder->classrooms()->attach($classroom->id);

        return $placeholder;
    }

    private function emailStudent(): User
    {
        return User::factory()->create(['role' => 'student', 'email' => 'jana@example.com']);
    }

    private function makeCourse(string $courseId = 'course-x', array $extra = []): Course
    {
        return Course::create(array_merge([
            'course_id' => $courseId,
            'name' => 'Course ' . $courseId,
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ], $extra));
    }

    // ── lookup ────────────────────────────────────────────────────────────

    public function test_lookup_names_the_class_and_the_roster_name_without_joining(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $this->mintPlaceholder($classroom, 'ABC123', 'Jana N.');
        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'abc123'])
            ->assertOk()
            ->assertJsonPath('data.classroom.id', $classroom->id)
            ->assertJsonPath('data.classroom.name', '5.A')
            ->assertJsonPath('data.student_name', 'Jana N.');

        $this->assertSame(0, $me->classrooms()->count(), 'lookup is a dry run');
        $this->assertNull(User::where('login_code', 'ABC123')->first()->merged_into_user_id);
    }

    // ── join ──────────────────────────────────────────────────────────────

    public function test_join_moves_the_membership_and_folds_the_placeholder_in(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $placeholder = $this->mintPlaceholder($classroom, 'ABC123');
        $course = $this->makeCourse();
        UserCourse::create(['user_id' => $placeholder->id, 'course_id' => $course->id, 'status' => 'downloaded', 'progress_percent' => 40]);

        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])
            ->assertOk()
            ->assertJsonPath('data.classroom.name', '5.A')
            ->assertJsonPath('data.classrooms.0.id', $classroom->id);

        // Membership moved: the roster lists me once, the placeholder is gone from it.
        $this->assertSame([$me->id], $classroom->students()->pluck('users.id')->all());

        // The placeholder was merged into me: code burned, its progress is mine.
        $placeholder->refresh();
        $this->assertSame($me->id, $placeholder->merged_into_user_id);
        $this->assertDatabaseHas('user_courses', ['user_id' => $me->id, 'course_id' => $course->id, 'progress_percent' => 40]);
        $this->assertDatabaseHas('user_merges', ['source_user_id' => $placeholder->id, 'target_user_id' => $me->id, 'performed_by' => $me->id]);

        // A burned code neither logs in nor joins again.
        $this->postJson('/api/resolve-code', ['code' => 'ABC123'])->assertStatus(410);
        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertStatus(410);
    }

    public function test_a_student_can_be_in_several_classes_at_once(): void
    {
        $maths = Classroom::create(['name' => 'Matematika 7']);
        $physics = Classroom::create(['name' => 'Fyzika 7']);
        $this->mintPlaceholder($maths, 'AAA111');
        $this->mintPlaceholder($physics, 'BBB222');

        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'AAA111'])->assertOk();
        $this->postJson('/api/user/classrooms/join', ['code' => 'BBB222'])
            ->assertOk()
            ->assertJsonCount(2, 'data.classrooms');

        $this->assertEqualsCanonicalizing(
            [$maths->id, $physics->id],
            $me->classrooms()->pluck('classrooms.id')->all(),
        );

        $this->getJson('/api/user/classrooms')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Matematika 7')
            ->assertJsonPath('data.1.name', 'Fyzika 7');

        $this->getJson('/api/user')->assertOk()->assertJsonCount(2, 'classrooms');
    }

    public function test_the_teacher_now_sees_the_joined_account_as_their_pupil(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->teachingClassrooms()->attach($classroom->id);
        $this->mintPlaceholder($classroom, 'ABC123');

        // Real bearer tokens on both sides: Sanctum::actingAs() would pin
        // the student onto the teacher's request too.
        $me = $this->emailStudent();
        $this->withToken($me->createToken('s')->plainTextToken)
            ->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertOk();

        $this->assertTrue($teacher->fresh()->teachesStudent($me->id));

        // The guard caches the resolved user for the whole test process;
        // drop it or the teacher's request is still answered as the student.
        app('auth')->forgetGuards();

        $ids = collect($this->withToken($teacher->createToken('t')->plainTextToken)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertContains($me->id, $ids);
    }

    // ── refusals ──────────────────────────────────────────────────────────

    public function test_a_guest_is_told_to_sign_in_first(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $this->mintPlaceholder($classroom, 'ABC123');
        Sanctum::actingAs(User::factory()->create(['email' => null, 'is_guest' => true]));

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ABC123'])->assertStatus(403);
        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertStatus(403);
        $this->assertSame(1, $classroom->students()->count());
    }

    public function test_unknown_codes_and_course_pins_are_not_classes(): void
    {
        $this->makeCourse('pinned', ['pin' => 'PIN999']);
        Sanctum::actingAs($this->emailStudent());

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ZZZ999'])->assertStatus(404);
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'PIN999'])->assertStatus(404);
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ABC'])->assertStatus(422);
    }

    public function test_already_a_member_is_refused_with_the_class_name(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $this->mintPlaceholder($classroom, 'ABC123');
        $this->mintPlaceholder($classroom, 'DEF456');
        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertOk();

        // A second code for the same class does not merge a second placeholder.
        $this->postJson('/api/user/classrooms/join', ['code' => 'DEF456'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Už jsi ve třídě 5.A.');
        $this->assertNull(User::where('login_code', 'DEF456')->first()->merged_into_user_id);
    }

    public function test_a_code_belonging_to_a_real_account_or_a_minor_is_refused(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);

        $claimed = $this->mintPlaceholder($classroom, 'ABC123');
        $claimed->update(['email' => 'someone@example.com']);

        $child = $this->mintPlaceholder($classroom, 'KID111');
        $child->update(['is_minor' => true]);

        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertStatus(409);
        $this->postJson('/api/user/classrooms/join', ['code' => 'KID111'])->assertStatus(403);
        $this->assertSame(0, $me->classrooms()->count());
    }

    /**
     * BR-BERW9B: a teacher signed in to the app joins by student code the
     * same way a student does — the placeholder is folded in and the code
     * burned. Only this self-service join lifts the merge's staff guard;
     * the admin merge tool still refuses staff accounts (UserMergeTest).
     */
    public function test_a_teacher_joins_by_student_code_and_keeps_their_role(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $placeholder = $this->mintPlaceholder($classroom, 'ABC123');
        $course = $this->makeCourse();
        UserCourse::create(['user_id' => $placeholder->id, 'course_id' => $course->id, 'status' => 'downloaded', 'progress_percent' => 40]);

        $me = User::factory()->create(['role' => 'teacher', 'email' => 'ucitel@example.com']);
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ABC123'])->assertOk();
        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])
            ->assertOk()
            ->assertJsonPath('data.classroom.id', $classroom->id);

        $this->assertSame([$me->id], $classroom->students()->pluck('users.id')->all());
        $this->assertSame($me->id, $placeholder->fresh()->merged_into_user_id);
        $this->assertDatabaseHas('user_courses', ['user_id' => $me->id, 'course_id' => $course->id, 'progress_percent' => 40]);

        $me->refresh();
        $this->assertSame('teacher', $me->role);
        $this->assertSame('ucitel@example.com', $me->email);
    }

    public function test_a_code_login_student_cannot_join_with_their_own_code(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $placeholder = $this->mintPlaceholder($classroom, 'ABC123');
        Sanctum::actingAs($placeholder);

        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertStatus(409);
    }

    // ── admin side of the pivot ───────────────────────────────────────────

    public function test_admin_can_replace_a_students_classroom_set(): void
    {
        $a = Classroom::create(['name' => '5.A']);
        $b = Classroom::create(['name' => '5.B']);
        $student = User::factory()->inClassroom($a)->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson("/api/admin/users/{$student->id}", ['classroom_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.classroom_ids', [$a->id, $b->id])
            ->assertJsonPath('data.classroom_name', '5.A, 5.B');

        $this->putJson("/api/admin/users/{$student->id}", ['classroom_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.classroom_ids', [])
            ->assertJsonPath('data.classroom_name', null);

        $this->getJson("/api/admin/users?classroom_ids[]={$a->id}")
            ->assertOk()
            ->assertJsonMissing(['id' => $student->id]);
    }

    public function test_deleting_a_class_keeps_the_accounts_that_joined_it(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $other = Classroom::create(['name' => '5.B']);
        $this->mintPlaceholder($classroom, 'ABC123');
        $lonePlaceholder = $this->mintPlaceholder($classroom, 'DEF456');
        $shared = $this->mintPlaceholder($classroom, 'GHI789');
        $shared->classrooms()->attach($other->id);

        $me = $this->emailStudent();
        Sanctum::actingAs($me);
        $this->postJson('/api/user/classrooms/join', ['code' => 'ABC123'])->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson("/api/admin/classrooms/{$classroom->id}")->assertOk();

        $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseMissing('users', ['id' => $lonePlaceholder->id]);
        $this->assertDatabaseHas('users', ['id' => $me->id]);
        $this->assertDatabaseHas('users', ['id' => $shared->id]);
        $this->assertSame(0, $me->fresh()->classrooms()->count());
        $this->assertSame([$other->id], $shared->fresh()->classrooms()->pluck('classrooms.id')->all());
    }
}
