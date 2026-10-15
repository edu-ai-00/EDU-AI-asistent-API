<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\EloInteraction;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserEloProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-87QQEU: the class Dovednosti heatmap showed "—" for every student whose
 * own detail page showed skills.
 *
 * The first answer in a dimension seeds the student's ELO without bumping
 * profil_pocet (EloEngine, mirroring the app), and the class view dropped
 * every dimension under the course's min_count (10) — so a dimension needed
 * eleven answers to appear. The student detail and the app count any
 * dimension with an ELO; the class view now does the same. "Pouze kurz" also
 * looked up the course's interactions by its numeric id, while
 * elo_interactions stores the string course_id, so that mode never found any.
 */
class ClassroomSkillsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Odvážný Mario']);

        $this->classroom = Classroom::create(['name' => 'Áčko']);
        $this->classroom->teachers()->attach($this->teacher->id);
        $this->classroom->students()->attach($this->student->id);

        $this->course = $this->makeCourse('matematika-test-zs');
        UserCourse::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'in_progress',
        ]);

        // One answer per dimension: ratings seeded, counts still zero.
        $profilElo = array_fill(0, 35, null);
        $profilElo[0] = 5.8;   // Číslo a operace
        $profilElo[17] = 6.0;  // Míry
        $profilElo[22] = 6.0;  // Geometrie
        $profilElo[29] = 8.0;  // Algebra
        UserEloProfile::create([
            'user_id' => $this->student->id,
            'profil_elo' => $profilElo,
            'profil_pocet' => array_fill(0, 35, 0),
        ]);

        // This course touched N and M; another course touched G and A.
        EloInteraction::create([
            'user_id' => $this->student->id, 'block_id' => 'b1', 'course_id' => 'matematika-test-zs',
            'score' => 1.0, 'updated_indices' => [0, 17],
        ]);
        EloInteraction::create([
            'user_id' => $this->student->id, 'block_id' => 'b2', 'course_id' => 'jina-matematika',
            'score' => 1.0, 'updated_indices' => [22, 29],
        ]);
    }

    private function makeCourse(string $courseId): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => 'C ' . $courseId,
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ]);
    }

    /**
     * @return array<string, float|null> skill name => level
     */
    private function levels(bool $aggregated): array
    {
        $query = 'course_id=' . $this->course->id . ($aggregated ? '&aggregated=true' : '');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->teacher->createToken('t')->plainTextToken)
            ->getJson('/api/admin/classrooms/' . $this->classroom->id . '/skills/overview?' . $query)
            ->assertOk();

        $students = $response->json('data.students');
        $this->assertCount(1, $students);
        $this->assertSame($this->student->id, $students[0]['user']['id']);

        return collect($students[0]['skills'])->pluck('level', 'name')->all();
    }

    public function test_aggregated_view_shows_every_dimension_with_an_elo_like_the_student_detail(): void
    {
        $this->assertEquals([
            'Číslo a operace' => 5.8,
            'Míry' => 6.0,
            'Geometrie' => 6.0,
            'Statistika a pravděpodobnost' => null,
            'Algebra' => 8.0,
        ], $this->levels(aggregated: true));
    }

    public function test_course_only_view_shows_the_dimensions_this_course_practised(): void
    {
        $this->assertEquals([
            'Číslo a operace' => 5.8,
            'Míry' => 6.0,
            'Geometrie' => null,
            'Statistika a pravděpodobnost' => null,
            'Algebra' => null,
        ], $this->levels(aggregated: false));
    }
}
