<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\DashboardBanner;
use App\Models\School;
use App\Models\User;
use App\Models\UserCourse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-SAWX2U: the dashboard's XP-banded banners, moved out of the app's code
 * into admin-managed rows.
 *
 * The contract the app depends on: the server filters by the conditions the
 * device cannot evaluate (role, course, class, school) and hands the XP band
 * over untouched, because the device's XP is the authoritative one between
 * syncs. A banner is therefore never withheld on XP grounds here.
 */
class DashboardBannerTest extends TestCase
{
    use RefreshDatabase;

    private function banner(array $attributes = []): DashboardBanner
    {
        return DashboardBanner::create($attributes + [
            'name' => 'Banner',
            'title' => 'Nadpis',
            'body' => 'Text',
            'is_active' => true,
        ]);
    }

    private function studentToken(User $student): string
    {
        return $student->createToken('auth_token')->plainTextToken;
    }

    /** @return array{0: User, 1: string} */
    private function admin(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return [$admin, $admin->createToken('admin-token', ['admin'])->plainTextToken];
    }

    public function test_seeded_entrance_exam_banners_replace_the_hard_coded_ones(): void
    {
        $intro = DashboardBanner::where('key', 'system.jpz_intro')->first();
        $cermatik = DashboardBanner::where('key', 'system.jpz_cermatik')->first();

        $this->assertNotNull($intro);
        $this->assertNotNull($cermatik);

        // The thresholds the app used: intro up to 100 XP, Cermatík past it.
        $this->assertNull($intro->min_xp);
        $this->assertSame(100, $intro->max_xp);
        $this->assertSame(101, $cermatik->min_xp);
        $this->assertNull($cermatik->max_xp);

        $this->assertSame('/field-course/EDU_JPZ_INTRO', $intro->link);
        $this->assertSame('/chat/cermatik', $cermatik->link);
        $this->assertTrue($intro->is_active && $intro->is_system);
    }

    public function test_app_gets_active_banners_in_position_order(): void
    {
        DashboardBanner::query()->delete();
        $student = User::factory()->create(['role' => 'student']);

        $this->banner(['name' => 'Druhý', 'position' => 20]);
        $this->banner(['name' => 'První', 'position' => 10]);
        $this->banner(['name' => 'Vypnutý', 'position' => 5, 'is_active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->studentToken($student))
            ->getJson('/api/dashboard/banners')
            ->assertOk();

        $this->assertSame(['První', 'Druhý'], array_map(
            fn (array $row) => DashboardBanner::find($row['id'])->name,
            $response->json('data'),
        ));
    }

    public function test_xp_band_travels_to_the_app_instead_of_filtering_there(): void
    {
        DashboardBanner::query()->delete();
        $student = User::factory()->create(['role' => 'student']);

        // A band this student's server-side XP (0) is nowhere near. It must
        // still be delivered — only the device knows the current number.
        $this->banner(['min_xp' => 500, 'max_xp' => 900]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->studentToken($student))
            ->getJson('/api/dashboard/banners')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(500, $response->json('data.0.min_xp'));
        $this->assertSame(900, $response->json('data.0.max_xp'));
    }

    public function test_course_class_and_school_conditions_filter_the_list(): void
    {
        DashboardBanner::query()->delete();

        $school = School::create(['name' => 'ZŠ Testovací']);
        $classroom = Classroom::create(['name' => 'Áčko', 'school_id' => $school->id]);
        $course = Course::create([
            'course_id' => 'EDU_TEST_MAT',
            'name' => 'Matematika',
            'status' => 'published',
            'data' => ['lessons' => []],
        ]);

        $member = User::factory()->create(['role' => 'student']);
        $classroom->students()->attach($member->id);
        UserCourse::create([
            'user_id' => $member->id,
            'course_id' => $course->id,
            'status' => 'in_progress',
        ]);

        $outsider = User::factory()->create(['role' => 'student']);

        $this->banner(['name' => 'Kurz', 'course_id' => $course->id, 'position' => 10]);
        $this->banner(['name' => 'Třída', 'classroom_id' => $classroom->id, 'position' => 20]);
        $this->banner(['name' => 'Škola', 'school_id' => $school->id, 'position' => 30]);
        $this->banner(['name' => 'Všichni', 'position' => 40]);

        $forMember = $this->withHeader('Authorization', 'Bearer '.$this->studentToken($member))
            ->getJson('/api/dashboard/banners')
            ->assertOk();
        $this->assertCount(4, $forMember->json('data'));

        // Second bearer token in one test: drop the guard's cached user.
        app('auth')->forgetGuards();

        $forOutsider = $this->withHeader('Authorization', 'Bearer '.$this->studentToken($outsider))
            ->getJson('/api/dashboard/banners')
            ->assertOk();
        $this->assertCount(1, $forOutsider->json('data'));
        $this->assertSame('Všichni', DashboardBanner::find($forOutsider->json('data.0.id'))->name);
    }

    public function test_role_targeting_follows_the_notification_rule(): void
    {
        DashboardBanner::query()->delete();

        $this->banner(['name' => 'Žákům', 'target_role' => 'student']);
        $this->banner(['name' => 'Týmu', 'target_role' => 'teacher']);

        $student = User::factory()->create(['role' => 'student']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        // An admin is on the team, so a teacher-facing banner reaches them.
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([[$student, 'Žákům'], [$teacher, 'Týmu'], [$admin, 'Týmu']] as [$user, $expected]) {
            // The guard caches the user it resolved first, so a second bearer
            // token in the same test would still answer as the first one.
            app('auth')->forgetGuards();

            $response = $this->withHeader('Authorization', 'Bearer '.$user->createToken('auth_token')->plainTextToken)
                ->getJson('/api/dashboard/banners')
                ->assertOk();

            $this->assertCount(1, $response->json('data'), "{$expected}: one banner expected");
            $this->assertSame($expected, DashboardBanner::find($response->json('data.0.id'))->name);
        }
    }

    public function test_admin_creates_a_banner(): void
    {
        [, $token] = $this->admin();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/dashboard-banners', [
                'name' => 'Intro kurz',
                'title' => 'Začni tady',
                'body' => 'Projdi si úvod',
                'badge' => 'NOVÉ',
                'link' => '/field-course/EDU_ONBOARDING',
                'min_xp' => 0,
                'max_xp' => 30,
                'is_active' => true,
                'position' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Intro kurz')
            ->assertJsonPath('data.max_xp', 30)
            ->assertJsonPath('data.is_system', false);

        $this->assertDatabaseHas('dashboard_banners', ['name' => 'Intro kurz', 'position' => 5]);
    }

    public function test_admin_cannot_save_a_link_the_app_cannot_open(): void
    {
        [, $token] = $this->admin();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/dashboard-banners', [
                'name' => 'Rozbitý',
                'title' => 'Nadpis',
                'body' => 'Text',
                'link' => 'https://example.com/phishing',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['link']);
    }

    public function test_admin_cannot_invert_the_xp_band(): void
    {
        [, $token] = $this->admin();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/dashboard-banners', [
                'name' => 'Obrácený',
                'title' => 'Nadpis',
                'body' => 'Text',
                'min_xp' => 100,
                'max_xp' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_xp']);
    }

    public function test_system_banner_can_be_switched_off_but_not_deleted(): void
    {
        [, $token] = $this->admin();
        $system = DashboardBanner::where('key', 'system.jpz_intro')->firstOrFail();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/dashboard-banners/{$system->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/dashboard-banners/{$system->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('dashboard_banners', ['key' => 'system.jpz_intro']);
    }

    public function test_banner_admin_is_closed_to_teachers(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->withHeader('Authorization', 'Bearer '.$teacher->createToken('admin-token', ['admin'])->plainTextToken)
            ->getJson('/api/admin/dashboard-banners')
            ->assertForbidden();
    }
}
