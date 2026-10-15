<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use App\Models\UserCourse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-TT6TD7: a class can carry a four-character class code the teacher
 * picks. Any signed-in student who types it (or opens /join/XXXX) lands on
 * the roster directly — no placeholder, no merge — and gets the class's
 * courses, the same as a pupil the teacher adds by hand.
 *
 * The student side reuses /user/classrooms/lookup|join; the length of the
 * code (4 vs 6) is what tells a class code from a student code.
 */
class ClassroomJoinCodeTest extends TestCase
{
    use RefreshDatabase;

    private function emailStudent(string $email = 'jana@example.com'): User
    {
        return User::factory()->create(['role' => 'student', 'email' => $email]);
    }

    private function makeCourse(string $courseId = 'course-x'): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => 'Course ' . $courseId,
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ]);
    }

    // ── admin: setting the code ───────────────────────────────────────────

    public function test_admin_sets_the_code_upper_cased_and_reads_it_back(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson("/api/admin/classrooms/{$classroom->id}", ['join_code' => 'ab12'])
            ->assertOk()
            ->assertJsonPath('data.join_code', 'AB12');

        $this->getJson("/api/admin/classrooms/{$classroom->id}")
            ->assertOk()
            ->assertJsonPath('data.join_code', 'AB12');

        $this->getJson('/api/admin/classrooms')
            ->assertOk()
            ->assertJsonPath('data.0.join_code', 'AB12');
    }

    public function test_the_code_is_optional_and_can_be_switched_off(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $created = $this->postJson('/api/admin/classrooms', [
            'name' => '5.A',
            'students' => [['name' => 'Jana', 'login_code' => 'ABC123']],
        ])->assertCreated()->assertJsonPath('data.join_code', null);

        $id = $created->json('data.id');

        $this->putJson("/api/admin/classrooms/{$id}", ['join_code' => 'AB12'])->assertOk();
        $this->putJson("/api/admin/classrooms/{$id}", ['join_code' => ''])
            ->assertOk()
            ->assertJsonPath('data.join_code', null);

        // Renaming alone leaves the code where it was.
        $this->putJson("/api/admin/classrooms/{$id}", ['join_code' => 'AB12'])->assertOk();
        $this->putJson("/api/admin/classrooms/{$id}", ['name' => '5.A (2026)'])
            ->assertOk()
            ->assertJsonPath('data.join_code', 'AB12');
    }

    public function test_the_code_can_be_given_when_creating_the_class(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/classrooms', [
            'name' => '5.A',
            'join_code' => 'xy9z',
            'students' => [['name' => 'Jana', 'login_code' => 'ABC123']],
        ])->assertCreated()->assertJsonPath('data.join_code', 'XY9Z');
    }

    public function test_the_code_is_unique_case_insensitively(): void
    {
        Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $other = Classroom::create(['name' => '5.B']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson("/api/admin/classrooms/{$other->id}", ['join_code' => 'ab12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.join_code.0', 'Tento kód třídy už používá jiná třída. Zvolte jiný.');

        // Saving a class with its own current code is not a collision.
        $mine = Classroom::create(['name' => '5.C', 'join_code' => 'CD34']);
        $this->putJson("/api/admin/classrooms/{$mine->id}", ['join_code' => 'CD34', 'name' => '5.C'])
            ->assertOk();
    }

    public function test_the_code_must_be_exactly_four_ascii_letters_or_digits(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['AB1', 'AB123', 'AB-1', 'ČŘ12', 'AB 1'] as $bad) {
            $this->putJson("/api/admin/classrooms/{$classroom->id}", ['join_code' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('join_code');
        }

        $this->assertNull($classroom->fresh()->join_code);
    }

    public function test_the_classs_own_teacher_may_set_it_but_nobody_else(): void
    {
        $classroom = Classroom::create(['name' => '5.A']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->teachingClassrooms()->attach($classroom->id);

        Sanctum::actingAs($teacher);
        $this->putJson("/api/admin/classrooms/{$classroom->id}", ['join_code' => 'AB12'])
            ->assertOk()
            ->assertJsonPath('data.join_code', 'AB12');

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));
        $this->putJson("/api/admin/classrooms/{$classroom->id}", ['join_code' => 'ZZ99'])
            ->assertStatus(403);
        $this->assertSame('AB12', $classroom->fresh()->join_code);
    }

    public function test_a_school_mate_teacher_sees_the_class_but_not_its_code(): void
    {
        $school = School::create(['name' => 'ZŠ Komenského']);
        $classroom = Classroom::create(['name' => '5.A', 'school_id' => $school->id, 'join_code' => 'AB12']);

        $colleague = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($colleague->id);
        Sanctum::actingAs($colleague);

        $row = collect($this->getJson('/api/admin/classrooms')->assertOk()->json('data'))
            ->firstWhere('id', $classroom->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['is_own']);
        $this->assertNull($row['join_code']);
    }

    public function test_a_self_service_family_class_cannot_get_a_code(): void
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);
        $classroom = Classroom::create([
            'name' => 'Samoobsluha',
            'type' => 'self_service',
            'guardian_id' => $guardian->id,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson("/api/admin/classrooms/{$classroom->id}", ['join_code' => 'AB12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_code');
        $this->assertNull($classroom->fresh()->join_code);
    }

    // ── student: lookup + join ────────────────────────────────────────────

    public function test_lookup_names_the_class_without_joining(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ab12'])
            ->assertOk()
            ->assertJsonPath('data.classroom.id', $classroom->id)
            ->assertJsonPath('data.classroom.name', '5.A')
            ->assertJsonPath('data.student_name', null);

        $this->assertSame(0, $me->classrooms()->count(), 'lookup is a dry run');
    }

    public function test_join_puts_the_student_on_the_roster_with_the_class_courses(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $course = $this->makeCourse();
        $classroom->courses()->attach($course->id);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->teachingClassrooms()->attach($classroom->id);

        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])
            ->assertOk()
            ->assertJsonPath('data.classroom.name', '5.A')
            ->assertJsonPath('data.classrooms.0.id', $classroom->id);

        $this->assertSame([$me->id], $classroom->students()->pluck('users.id')->all());
        $this->assertDatabaseHas('user_courses', ['user_id' => $me->id, 'course_id' => $course->id, 'status' => 'downloaded']);
        $this->assertDatabaseCount('user_merges', 0);
        $this->assertTrue($teacher->fresh()->teachesStudent($me->id));

        // The code is reusable — it is the class's, not one pupil's.
        Sanctum::actingAs($this->emailStudent('petr@example.com'));
        $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])->assertOk();
        $this->assertSame(2, $classroom->students()->count());
    }

    public function test_join_keeps_progress_on_a_course_the_student_already_has(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $course = $this->makeCourse();
        $classroom->courses()->attach($course->id);

        $me = $this->emailStudent();
        UserCourse::create(['user_id' => $me->id, 'course_id' => $course->id, 'status' => 'in_progress', 'progress_percent' => 60]);
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])->assertOk();

        $this->assertDatabaseHas('user_courses', ['user_id' => $me->id, 'course_id' => $course->id, 'progress_percent' => 60]);
        $this->assertSame(1, UserCourse::where('user_id', $me->id)->count());
    }

    public function test_a_member_is_told_they_are_already_in_the_class(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $me = $this->emailStudent();
        $me->classrooms()->attach($classroom->id);
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'AB12'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Už jsi ve třídě 5.A.');
        $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Už jsi ve třídě 5.A.');
        $this->assertSame(1, $classroom->students()->count());
    }

    public function test_a_code_login_student_can_join_another_class_by_class_code(): void
    {
        $home = Classroom::create(['name' => '5.A']);
        $club = Classroom::create(['name' => 'Kroužek robotiky', 'join_code' => 'ROBO']);
        $pupil = User::factory()->inClassroom($home)->create(['email' => null, 'login_code' => 'ABC123']);
        Sanctum::actingAs($pupil);

        $this->postJson('/api/user/classrooms/join', ['code' => 'robo'])
            ->assertOk()
            ->assertJsonCount(2, 'data.classrooms');

        $this->assertEqualsCanonicalizing(
            [$home->id, $club->id],
            $pupil->classrooms()->pluck('classrooms.id')->all(),
        );
    }

    public function test_unknown_or_malformed_codes_are_refused(): void
    {
        Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        Sanctum::actingAs($this->emailStudent());

        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ZZ99'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Kód třídy nebyl nalezen.');
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'AB1'])->assertStatus(422);
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'AB123'])->assertStatus(422);
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ČŘ12'])->assertStatus(422);
    }

    public function test_a_family_class_is_never_joinable_even_with_a_code_in_the_db(): void
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);
        Classroom::create([
            'name' => 'Samoobsluha',
            'type' => 'self_service',
            'guardian_id' => $guardian->id,
            'join_code' => 'FAM1',
        ]);
        $me = $this->emailStudent();
        Sanctum::actingAs($me);

        $this->postJson('/api/user/classrooms/join', ['code' => 'FAM1'])->assertStatus(404);
        $this->assertSame(0, $me->classrooms()->count());
    }

    public function test_guests_and_minors_cannot_join(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);

        Sanctum::actingAs(User::factory()->create(['email' => null, 'is_guest' => true]));
        $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Nejdřív se přihlas nebo zaregistruj.');

        Sanctum::actingAs(User::factory()->create(['email' => null, 'is_minor' => true]));
        $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tento účet se ke třídě připojit nemůže.');

        $this->assertSame(0, $classroom->students()->count());
    }

    public function test_a_refused_code_is_answered_not_reported_as_an_error(): void
    {
        // BR-472CMD: a refused join (unknown code, a minor's account, …) is
        // the product answering what was typed, not a server fault, so it
        // must not page Nightwatch as an exception.
        Exceptions::fake();
        Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);

        Sanctum::actingAs(User::factory()->create(['email' => null, 'is_minor' => true]));
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'AB12'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tento účet se ke třídě připojit nemůže.');

        Sanctum::actingAs($this->emailStudent());
        $this->postJson('/api/user/classrooms/lookup', ['code' => 'ZZ99'])->assertStatus(404);

        Exceptions::assertNothingReported();
    }

    /**
     * BR-BERW9B: a teacher or an admin signed in to the app is refused
     * nothing a student account is offered — the class code puts them on
     * the roster with the class's courses, keeping their role.
     */
    public function test_a_teacher_or_an_admin_joins_like_anyone_else(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $course = $this->makeCourse();
        $classroom->courses()->attach($course->id);

        foreach (['teacher', 'admin'] as $role) {
            $me = User::factory()->create(['role' => $role, 'email' => "{$role}@example.com"]);
            Sanctum::actingAs($me);

            $this->postJson('/api/user/classrooms/lookup', ['code' => 'AB12'])
                ->assertOk()
                ->assertJsonPath('data.classroom.name', '5.A');
            $this->postJson('/api/user/classrooms/join', ['code' => 'AB12'])
                ->assertOk()
                ->assertJsonPath('data.classroom.id', $classroom->id);

            $this->assertTrue($me->classrooms()->whereKey($classroom->id)->exists());
            $this->assertDatabaseHas('user_courses', ['user_id' => $me->id, 'course_id' => $course->id]);
            $this->assertSame($role, $me->fresh()->role);
        }
    }

    /**
     * A staff account on a roster is the class teacher's to see, never to
     * edit: changing its e-mail would hand that teacher the account's
     * sign-in (magic link), and with an admin the whole panel.
     */
    public function test_the_class_teacher_cannot_edit_a_staff_account_that_joined(): void
    {
        $classroom = Classroom::create(['name' => '5.A', 'join_code' => 'AB12']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->teachingClassrooms()->attach($classroom->id);

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'reditel@example.com', 'name' => 'Ředitel']);
        $this->withToken($admin->createToken('a')->plainTextToken)
            ->postJson('/api/user/classrooms/join', ['code' => 'AB12'])->assertOk();

        // The guard caches the resolved user for the whole test process;
        // drop it or the teacher's request is still answered as the admin.
        app('auth')->forgetGuards();
        $teacherToken = $teacher->createToken('t')->plainTextToken;

        $this->withToken($teacherToken)
            ->putJson("/api/admin/users/{$admin->id}", ['email' => 'ucitel@example.com', 'name' => 'Ukradeno'])
            ->assertStatus(403);

        $admin->refresh();
        $this->assertSame('reditel@example.com', $admin->email);
        $this->assertSame('Ředitel', $admin->name);
        $this->assertSame('admin', $admin->role);

        // A pupil on the same roster stays editable.
        $pupil = $this->emailStudent();
        $pupil->classrooms()->attach($classroom->id);
        $this->withToken($teacherToken)
            ->putJson("/api/admin/users/{$pupil->id}", ['name' => 'Jana N.'])
            ->assertOk();
        $this->assertSame('Jana N.', $pupil->fresh()->name);
    }
}
