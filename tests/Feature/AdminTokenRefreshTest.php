<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * BR-9SX9BC: admins were signed out a few hours after logging in, mid-work.
 *
 * Admin/teacher tokens live 48 hours and the panel's cookie lives exactly as
 * long (BR-4U7XUW), so the session has to be rotated through
 * POST /api/admin/auth/refresh while it is in use. The panel does that from its
 * backend proxy, which serves several requests at once — each one holding the
 * same token when it falls due. These tests pin what makes that safe: the
 * rotated-out token lingers for a short grace instead of dying on the spot, so
 * the sibling requests (and their own refreshes) still go through.
 *
 * BR-CAMYRM: the window used to be 8 hours, so a panel left alone overnight
 * opened on the sign-in page. The client asked for a sign-in that holds for
 * 48 hours from the last action.
 */
class AdminTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(User $user, $expiresAt = null): string
    {
        return $user->createToken('admin-token', ['admin'], $expiresAt ?? now()->addHours(48))
            ->plainTextToken;
    }

    private function signIn(User $user): string
    {
        $code = VerificationCode::generateFor($user->email, 10);

        app('auth')->forgetGuards();

        return $this->postJson('/api/admin/auth/verify', [
            'email' => $user->email,
            'code' => $code->code,
        ])->assertOk()->json('token');
    }

    private function refreshWith(string $token)
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/auth/refresh');
    }

    private function meWith(string $token)
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/auth/me');
    }

    public function test_refresh_issues_a_new_token_with_a_full_window(): void
    {
        config(['sanctum.admin_expiration_minutes' => 480]);
        $admin = User::factory()->create(['role' => 'admin']);
        $old = $this->adminToken($admin, now()->addHour());

        $response = $this->refreshWith($old)->assertOk();

        $fresh = $response->json('token');
        $this->assertNotSame($old, $fresh);
        $this->assertTrue(
            now()->addMinutes(479)->lessThan($response->json('expires_at')),
            'The new token gets the whole admin window, not what was left of the old one.'
        );
        $this->meWith($fresh)->assertOk()->assertJsonPath('data.id', $admin->id);
    }

    public function test_sign_in_and_refresh_issue_a_48_hour_window(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->freezeSecond();
        $token = $this->signIn($admin);
        $this->assertSame(
            now()->addHours(48)->toIso8601String(),
            PersonalAccessToken::findToken($token)->expires_at->toIso8601String()
        );

        $this->travel(15)->minutes();
        $this->refreshWith($token)->assertOk()
            ->assertJsonPath('expires_at', now()->addHours(48)->toIso8601String());
    }

    public function test_session_lasts_48_hours_from_the_last_action(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $token = $this->signIn($teacher);

        // Left alone overnight and through the next day: the next request
        // still finds the session, and the panel rotates it on the way.
        $this->travel(47)->hours();
        $this->meWith($token)->assertOk();
        $token = $this->refreshWith($token)->assertOk()->json('token');

        // The 48 hours run again from that action, not from the sign-in.
        $this->travel(47)->hours();
        $this->meWith($token)->assertOk();

        $this->travel(2)->hours();
        $this->meWith($token)->assertStatus(401);
        $this->refreshWith($token)->assertStatus(401);
    }

    public function test_parallel_refreshes_with_the_same_token_all_succeed(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $old = $this->adminToken($teacher);

        // Two proxied requests fell due together; the second refresh reaches
        // the API after the first one already rotated the token.
        $first = $this->refreshWith($old)->assertOk()->json('token');
        $second = $this->refreshWith($old)->assertOk()->json('token');

        $this->meWith($first)->assertOk();
        $this->meWith($second)->assertOk();
    }

    public function test_rotated_out_token_keeps_working_during_the_grace_only(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $old = $this->adminToken($teacher);

        $this->refreshWith($old)->assertOk();

        // A request that set off with the old token just before the rotation.
        $this->meWith($old)->assertOk();

        $this->travel(2)->minutes();

        $this->meWith($old)->assertStatus(401);
    }

    public function test_grace_never_extends_a_token_that_was_about_to_expire(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $old = $this->adminToken($teacher, now()->addSeconds(10));

        $this->refreshWith($old)->assertOk();

        $record = PersonalAccessToken::findToken($old);
        $this->assertTrue(
            $record->expires_at->lessThanOrEqualTo(now()->addSeconds(10)),
            'Rotating a token must not buy it more time than it already had.'
        );
    }

    public function test_expired_token_cannot_refresh(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $dead = $this->adminToken($teacher, now()->subMinute());

        $this->refreshWith($dead)->assertStatus(401);
    }
}
