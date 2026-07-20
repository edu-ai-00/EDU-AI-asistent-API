<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Models\UserCourse;
use App\Models\VerificationCode;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest → full-account upgrade must carry the guest's in-progress courses over
 * to the account being signed into, instead of orphaning them on the guest row
 * (BR-4FTCFH).
 *
 * Covers the self-service merge convenience method on UserMergeService and the
 * two endpoints that trigger it: POST /api/email/verify (guest signs into a
 * pre-existing account) and POST /api/guest/claim (email turns out to be taken).
 */
class GuestUpgradeMergeTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(string $courseId, string $name = 'Course'): Course
    {
        return Course::create([
            'course_id' => $courseId,
            'name' => $name,
            'version' => 1,
            'status' => 'published',
            'language' => 'cs',
            'data' => [],
        ]);
    }

    private function enroll(User $user, Course $course, int $progress = 40): UserCourse
    {
        return UserCourse::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'progress_percent' => $progress,
            'status' => 'in_progress',
            'completed_lessons' => 1,
            'current_lesson_index' => 1,
            'time_spent_seconds' => 600,
            'started_at' => now()->subDay(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Service: UserMergeService::absorbGuest
    // ─────────────────────────────────────────────────────────────────────────

    public function test_absorb_guest_reassigns_guest_only_course_to_target(): void
    {
        $service = app(UserMergeService::class);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $target = User::factory()->create(['is_guest' => false, 'email' => 'full@example.test']);

        $course = $this->makeCourse('vyrazy-zs', 'Výrazy ZŠ');
        $this->enroll($guest, $course, 55);

        $merge = $service->absorbGuest($guest, $target);

        $this->assertNotNull($merge);
        // The course now belongs to the full account, not the guest.
        $this->assertDatabaseHas('user_courses', ['user_id' => $target->id, 'course_id' => $course->id]);
        $this->assertDatabaseMissing('user_courses', ['user_id' => $guest->id, 'course_id' => $course->id]);
        // The guest is soft-merged into the target.
        $this->assertSame($target->id, $guest->fresh()->merged_into_user_id);
    }

    public function test_absorb_guest_is_noop_for_non_guest_source(): void
    {
        $service = app(UserMergeService::class);

        $notGuest = User::factory()->create(['is_guest' => false, 'email' => 'a@example.test']);
        $target = User::factory()->create(['is_guest' => false, 'email' => 'b@example.test']);

        $this->assertNull($service->absorbGuest($notGuest, $target));
        $this->assertNull($notGuest->fresh()->merged_into_user_id);
    }

    public function test_absorb_guest_is_noop_for_admin_target(): void
    {
        $service = app(UserMergeService::class);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $admin = User::factory()->create(['is_guest' => false, 'role' => 'admin', 'email' => 'admin@example.test']);

        $course = $this->makeCourse('c-admin');
        $this->enroll($guest, $course);

        // Merging a guest into an admin account is refused (returns null); the
        // guest's data is left untouched rather than folded into the admin.
        $this->assertNull($service->absorbGuest($guest, $admin));
        $this->assertDatabaseHas('user_courses', ['user_id' => $guest->id, 'course_id' => $course->id]);
        $this->assertNull($guest->fresh()->merged_into_user_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint: POST /api/email/verify folds a guest into an existing account
    // ─────────────────────────────────────────────────────────────────────────

    public function test_email_verify_merges_guest_into_existing_account(): void
    {
        $existing = User::factory()->create([
            'is_guest' => false,
            'email' => 'student@example.test',
            'name' => 'Student',
        ]);
        $existingCourse = $this->makeCourse('c-existing', 'Existing');
        $this->enroll($existing, $existingCourse, 20);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null, 'name' => 'Host']);
        $guestCourse = $this->makeCourse('c-guest', 'Guest course');
        $this->enroll($guest, $guestCourse, 70);

        $token = $guest->createToken('auth-token')->plainTextToken;
        $code = VerificationCode::generateFor('student@example.test', 10)->code;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/email/verify', [
                'email' => 'student@example.test',
                'code' => $code,
            ]);

        $response->assertOk()->assertJson(['valid' => true, 'user_exists' => true]);
        $this->assertNotEmpty($response->json('token'));

        // The existing account now owns BOTH courses.
        $this->assertDatabaseHas('user_courses', ['user_id' => $existing->id, 'course_id' => $existingCourse->id]);
        $this->assertDatabaseHas('user_courses', ['user_id' => $existing->id, 'course_id' => $guestCourse->id]);
        // Nothing is left orphaned under the guest; the guest is soft-merged.
        $this->assertSame(0, UserCourse::where('user_id', $guest->id)->count());
        $this->assertSame($existing->id, $guest->fresh()->merged_into_user_id);
    }

    public function test_email_verify_without_guest_token_still_logs_in_existing_user(): void
    {
        $existing = User::factory()->create([
            'is_guest' => false,
            'email' => 'solo@example.test',
            'name' => 'Solo',
        ]);
        $code = VerificationCode::generateFor('solo@example.test', 10)->code;

        // No Authorization header — regression check that the added auth.optional
        // middleware doesn't change the anonymous verify path.
        $response = $this->postJson('/api/email/verify', [
            'email' => 'solo@example.test',
            'code' => $code,
        ]);

        $response->assertOk()->assertJson(['valid' => true, 'user_exists' => true]);
        $this->assertNotEmpty($response->json('token'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoint: POST /api/guest/claim merges when the email is already taken
    // ─────────────────────────────────────────────────────────────────────────

    public function test_guest_claim_merges_into_existing_account_when_email_taken(): void
    {
        $existing = User::factory()->create([
            'is_guest' => false,
            'email' => 'taken@example.test',
            'name' => 'Owner',
        ]);

        $guest = User::factory()->create(['is_guest' => true, 'email' => null, 'name' => 'Host']);
        $guestCourse = $this->makeCourse('c-claim', 'Claim course');
        $this->enroll($guest, $guestCourse, 90);

        $token = $guest->createToken('auth-token')->plainTextToken;
        // The email must be recently verified for claim to proceed.
        VerificationCode::generateFor('taken@example.test', 10)->markAsVerified();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/guest/claim', [
                'email' => 'taken@example.test',
                'name' => 'Host',
            ]);

        $response->assertOk()->assertJson(['success' => true, 'merged' => true]);
        $this->assertSame($existing->id, $response->json('user.id'));

        // Guest course folded into the existing account; guest soft-merged.
        $this->assertDatabaseHas('user_courses', ['user_id' => $existing->id, 'course_id' => $guestCourse->id]);
        $this->assertSame($existing->id, $guest->fresh()->merged_into_user_id);
    }
}
