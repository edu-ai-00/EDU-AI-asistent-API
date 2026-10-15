<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Course;
use App\Models\Guardian;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\UserStats;
use App\Services\GoogleIdTokenVerifier;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A guest's progress must fold into the account being signed into on EVERY
 * login path, not just /api/email/verify (BR-4FTCFH). Covers the two paths
 * that previously dropped it — student login code (/api/resolve-code) and
 * OAuth — plus the shared-device opt-out (BR-ZDXP7C) and the monotonic
 * server guards that protect merged values from stale client pushes.
 */
class GuestLoginMergeEndpointsTest extends TestCase
{
    use RefreshDatabase;

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

    private function enroll(User $user, Course $course, int $progress = 70): UserCourse
    {
        return UserCourse::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'progress_percent' => $progress,
            'status' => $progress >= 100 ? 'completed' : 'in_progress',
            'completed_lessons' => 3,
            'current_lesson_index' => 3,
            'time_spent_seconds' => 500,
            'started_at' => now()->subDay(),
            'completed_at' => $progress >= 100 ? now() : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/resolve-code (student login code)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_resolve_code_merges_guest_into_student_account(): void
    {
        $student = User::factory()->create([
            'is_guest' => false,
            'email' => 'zak@example.test',
            'login_code' => 'AB12CD',
        ]);
        $studentCourse = $this->makeCourse('c-student');
        $this->enroll($student, $studentCourse, 20);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestCourse = $this->makeCourse('c-guest');
        $this->enroll($guest, $guestCourse, 100);

        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/resolve-code', ['code' => 'AB12CD']);

        $response->assertOk()->assertJson(['type' => 'user']);
        $this->assertSame($student->id, $response->json('user.id'));

        // Student account now owns BOTH courses; guest soft-merged.
        $this->assertDatabaseHas('user_courses', ['user_id' => $student->id, 'course_id' => $studentCourse->id]);
        $this->assertDatabaseHas('user_courses', ['user_id' => $student->id, 'course_id' => $guestCourse->id]);
        $this->assertSame(0, UserCourse::where('user_id', $guest->id)->count());
        $this->assertSame($student->id, $guest->fresh()->merged_into_user_id);
    }

    public function test_resolve_code_on_shared_device_does_not_merge(): void
    {
        $student = User::factory()->create([
            'is_guest' => false,
            'email' => 'sdilene@example.test',
            'login_code' => 'EF34GH',
        ]);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestCourse = $this->makeCourse('c-shared');
        $this->enroll($guest, $guestCourse, 50);

        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/resolve-code', ['code' => 'EF34GH', 'shared_device' => true]);

        $response->assertOk()->assertJson(['type' => 'user']);

        // Shared device → likely a different person; guest data must NOT leak
        // into the student's account (BR-ZDXP7C).
        $this->assertDatabaseMissing('user_courses', ['user_id' => $student->id, 'course_id' => $guestCourse->id]);
        $this->assertNull($guest->fresh()->merged_into_user_id);
    }

    public function test_resolve_code_guest_upgrade_merges_even_on_shared_device(): void
    {
        // The guest tapped "log in" inside their own session and typed their
        // student code there. shared_device is the app's DEFAULT, so gating
        // the merge on it alone dropped every such guest's progress (BR-35W2HV)
        // — merge_guest says the guest is the one signing in.
        $student = User::factory()->create([
            'is_guest' => false,
            'email' => null,
            'login_code' => 'QR90ST',
        ]);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestCourse = $this->makeCourse('c-upgrade');
        $this->enroll($guest, $guestCourse, 60);
        UserStats::getOrCreateForUser($guest->id)->update(['xp_points' => 450]);

        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/resolve-code', [
                'code' => 'QR90ST',
                'shared_device' => true,
                'merge_guest' => true,
            ]);

        $response->assertOk()->assertJson(['type' => 'user', 'shared_device' => true]);
        $this->assertSame($student->id, $response->json('user.id'));

        $this->assertDatabaseHas('user_courses', ['user_id' => $student->id, 'course_id' => $guestCourse->id]);
        $this->assertSame(450, (int) UserStats::where('user_id', $student->id)->value('xp_points'));
        $this->assertSame($student->id, $guest->fresh()->merged_into_user_id);
    }

    public function test_resolve_code_guest_upgrade_never_merges_into_minor(): void
    {
        // merge_guest overrides the shared-device opt-out only — an adult
        // guest's history must still never land on a child's account
        // (BR-8FDF37).
        $guardian = Guardian::create(['email' => 'rodic@example.test', 'locale' => 'cs']);
        Consent::startFor($guardian, 'cs', 'CZ')
            ->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])
            ->save();

        $code = StudentCodes::generate();
        $minor = User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash($code),
        ]);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestCourse = $this->makeCourse('c-minor');
        $this->enroll($guest, $guestCourse, 60);
        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/resolve-code', ['code' => $code, 'merge_guest' => true]);

        $response->assertOk()->assertJson(['type' => 'user']);
        $this->assertSame($minor->id, $response->json('user.id'));
        $this->assertDatabaseMissing('user_courses', ['user_id' => $minor->id, 'course_id' => $guestCourse->id]);
        $this->assertNull($guest->fresh()->merged_into_user_id);
    }

    public function test_resolve_code_without_token_still_logs_in(): void
    {
        User::factory()->create([
            'is_guest' => false,
            'email' => 'plain@example.test',
            'login_code' => 'IJ56KL',
        ]);

        $response = $this->postJson('/api/resolve-code', ['code' => 'IJ56KL']);

        $response->assertOk()->assertJson(['type' => 'user']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_resolve_code_course_pin_still_works_for_guest(): void
    {
        $course = $this->makeCourse('c-pin');
        $course->update(['pin' => 'MN78OP']);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/resolve-code', ['code' => 'MN78OP']);

        $response->assertOk()->assertJson(['type' => 'course']);
        // Resolving a course PIN must never merge/tombstone the guest.
        $this->assertNull($guest->fresh()->merged_into_user_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OAuth (Google as representative — apple/microsoft share loginByEmail)
    // ─────────────────────────────────────────────────────────────────────────

    private function fakeGoogleVerifier(string $email): void
    {
        $mock = Mockery::mock(GoogleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn([
            'sub' => '123',
            'email' => $email,
            'email_verified' => true,
            'name' => 'OAuth User',
        ]);
        $this->instance(GoogleIdTokenVerifier::class, $mock);
    }

    public function test_google_login_merges_guest_into_existing_account(): void
    {
        $existing = User::factory()->create([
            'is_guest' => false,
            'email' => 'oauth@example.test',
            'selected_subjects' => ['math'],
        ]);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestCourse = $this->makeCourse('c-oauth');
        $this->enroll($guest, $guestCourse, 100);

        $this->fakeGoogleVerifier('oauth@example.test');
        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/google', ['id_token' => 'anything']);

        $response->assertOk();
        $this->assertSame($existing->id, $response->json('user.id'));

        $this->assertDatabaseHas('user_courses', ['user_id' => $existing->id, 'course_id' => $guestCourse->id]);
        $this->assertSame($existing->id, $guest->fresh()->merged_into_user_id);
    }

    public function test_google_login_on_shared_device_does_not_merge(): void
    {
        User::factory()->create(['is_guest' => false, 'email' => 'oauth2@example.test']);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $this->fakeGoogleVerifier('oauth2@example.test');
        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/google', ['id_token' => 'anything', 'shared_device' => true]);

        $response->assertOk();
        $this->assertNull($guest->fresh()->merged_into_user_id);
    }

    public function test_google_login_into_own_new_account_does_not_tombstone_guest_wrongly(): void
    {
        // Guest signs in with an email that has NO existing account: a fresh
        // user is created and the guest folds into it — nothing is lost.
        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestCourse = $this->makeCourse('c-new-oauth');
        $this->enroll($guest, $guestCourse, 40);

        $this->fakeGoogleVerifier('fresh@example.test');
        $token = $guest->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/google', ['id_token' => 'anything']);

        $response->assertStatus(201);
        $newUserId = $response->json('user.id');
        $this->assertDatabaseHas('user_courses', ['user_id' => $newUserId, 'course_id' => $guestCourse->id]);
        $this->assertSame($newUserId, $guest->fresh()->merged_into_user_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Monotonic guards — stale client pushes must not wipe merged values
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stats_push_cannot_lower_xp(): void
    {
        $user = User::factory()->create(['email' => 'stats@example.test']);
        $stats = UserStats::getOrCreateForUser($user->id);
        $stats->update(['xp_points' => 1300, 'level' => 2]);

        $response = $this->actingAs($user)
            ->putJson('/api/user/stats', ['xp_points' => 300, 'level' => 1, 'streak_days' => 0]);

        $response->assertOk();
        $fresh = UserStats::where('user_id', $user->id)->first();
        $this->assertSame(1300, (int) $fresh->xp_points, 'stale lower XP must not overwrite merged sum');
        $this->assertSame(2, (int) $fresh->level);
        // Streak is legitimately resettable — not guarded.
        $this->assertSame(0, (int) $fresh->streak_days);
    }

    public function test_course_push_cannot_regress_progress_or_completed_status(): void
    {
        $user = User::factory()->create(['email' => 'course@example.test']);
        $course = $this->makeCourse('c-guard');
        UserCourse::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'progress_percent' => 100, 'status' => 'completed',
            'completed_lessons' => 8, 'current_lesson_index' => 8,
            'time_spent_seconds' => 4900,
            'started_at' => now()->subDays(2), 'completed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->putJson("/api/user/courses/{$course->id}", [
            'progress_percent' => 30,
            'status' => 'in_progress',
            'completed_lessons' => 2,
            'time_spent_seconds' => 900,
        ]);

        $response->assertOk();
        $row = UserCourse::where('user_id', $user->id)->where('course_id', $course->id)->first();
        $this->assertSame(100, (int) $row->progress_percent);
        $this->assertSame('completed', $row->status);
        $this->assertSame(8, (int) $row->completed_lessons);
        $this->assertSame(4900, (int) $row->time_spent_seconds);
        $this->assertNotNull($row->completed_at);
    }

    public function test_course_push_can_still_advance_progress(): void
    {
        $user = User::factory()->create(['email' => 'course2@example.test']);
        $course = $this->makeCourse('c-forward');
        UserCourse::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'progress_percent' => 40, 'status' => 'in_progress',
            'completed_lessons' => 3, 'current_lesson_index' => 3,
            'time_spent_seconds' => 1000,
            'started_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->putJson("/api/user/courses/{$course->id}", [
            'progress_percent' => 100,
            'time_spent_seconds' => 2000,
        ]);

        $response->assertOk();
        $row = UserCourse::where('user_id', $user->id)->where('course_id', $course->id)->first();
        $this->assertSame(100, (int) $row->progress_percent);
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);
        $this->assertSame(2000, (int) $row->time_spent_seconds);
    }
}
