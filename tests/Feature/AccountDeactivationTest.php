<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\VerificationCode;
use App\Services\AppleIdTokenVerifier;
use App\Services\GoogleIdTokenVerifier;
use App\Services\MicrosoftIdTokenVerifier;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * BR-Q3ZJ6Q: an admin had no way to take access away from an account — no
 * delete, no deactivate; a team member could only be demoted to student.
 *
 * Deactivation is a state on the account (users.deactivated_at). Every login
 * path refuses it through one check (User::ensureCanSignIn(), reached from
 * User::createToken()), the tokens it holds are revoked at once, and an audit
 * row records who did it. Reactivation undoes it without touching data.
 */
class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithToken(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return [$admin, $admin->createToken('admin-token', ['admin'])->plainTextToken];
    }

    private function deactivated(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->deactivate(null);

        return $user->fresh();
    }

    private function assertRefusedAsDeactivated($response): void
    {
        $response->assertStatus(403)
            ->assertJsonPath('error', 'account_deactivated')
            ->assertJsonPath('message', 'Tento účet byl deaktivován. Pro obnovení přístupu kontaktujte správce.');
        $this->assertNull($response->json('token'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin endpoints
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_deactivation_revokes_tokens_and_records_an_audit_event(): void
    {
        [$admin, $adminToken] = $this->adminWithToken();
        $student = User::factory()->create();
        $student->createToken('auth_token');
        $student->createToken('auth_token');

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$student->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.id', $student->id);

        $this->assertNotNull($student->fresh()->deactivated_at);
        $this->assertSame(0, $student->tokens()->count());
        $this->assertDatabaseHas('user_account_events', [
            'user_id' => $student->id,
            'performed_by' => $admin->id,
            'event' => UserAccountEvent::DEACTIVATED,
        ]);
    }

    public function test_a_live_session_gets_401_immediately_after_deactivation(): void
    {
        [, $adminToken] = $this->adminWithToken();
        $student = User::factory()->create();
        $studentToken = $student->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$studentToken}")
            ->getJson('/api/user')
            ->assertOk();

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$student->id}/deactivate")
            ->assertOk();

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$studentToken}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_a_token_that_escaped_revocation_still_authenticates_nobody(): void
    {
        // A login already past its check when the admin clicked could mint a
        // token after the revoke. The Sanctum callback is what catches it.
        $student = User::factory()->create();
        $token = $student->createToken('auth_token')->plainTextToken;
        $student->forceFill(['deactivated_at' => now()])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertStatus(401);

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/refresh')
            ->assertStatus(401);
    }

    public function test_deactivating_twice_records_one_event(): void
    {
        [, $adminToken] = $this->adminWithToken();
        $student = User::factory()->create();

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$student->id}/deactivate")
            ->assertOk();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$student->id}/deactivate")
            ->assertOk();

        $this->assertSame(1, UserAccountEvent::where('user_id', $student->id)->count());
    }

    public function test_reactivation_lets_the_account_sign_in_again_and_keeps_its_data(): void
    {
        [$admin, $adminToken] = $this->adminWithToken();
        $classroom = Classroom::create(['name' => '6.A']);
        $student = User::factory()->create(['email' => 'zak@example.test']);
        $student->classrooms()->attach($classroom->id);
        $student->deactivate($admin);

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$student->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.deactivated_at', null);

        $this->assertNull($student->fresh()->deactivated_at);
        $this->assertSame([$classroom->id], $student->fresh()->classrooms->pluck('id')->all());
        $this->assertDatabaseHas('user_account_events', [
            'user_id' => $student->id,
            'performed_by' => $admin->id,
            'event' => UserAccountEvent::REACTIVATED,
        ]);

        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['email' => 'zak@example.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_a_teacher_cannot_deactivate(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer ' . $teacher->createToken('t')->plainTextToken)
            ->postJson("/api/admin/users/{$student->id}/deactivate")
            ->assertStatus(403);

        $this->assertNull($student->fresh()->deactivated_at);
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        [$admin, $adminToken] = $this->adminWithToken();

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$admin->id}/deactivate")
            ->assertStatus(422);

        $this->assertNull($admin->fresh()->deactivated_at);
    }

    public function test_a_deactivated_team_member_loses_the_panel_and_shows_so_on_the_roster(): void
    {
        [, $adminToken] = $this->adminWithToken();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacherToken = $teacher->createToken('admin-token', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/users/{$teacher->id}/deactivate")
            ->assertOk();

        $roster = collect(
            $this->withHeader('Authorization', "Bearer {$adminToken}")
                ->getJson('/api/admin/team')
                ->assertOk()
                ->json('data'),
        )->keyBy('id');
        $this->assertNotNull($roster[$teacher->id]['deactivated_at']);

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$teacherToken}")
            ->getJson('/api/admin/users')
            ->assertStatus(401);
    }

    public function test_user_detail_says_who_deactivated_the_account(): void
    {
        [$admin, $adminToken] = $this->adminWithToken();
        $student = User::factory()->create();
        $student->deactivate($admin);

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/admin/users/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.deactivated_by.id', $admin->id)
            ->assertJsonPath('data.deactivated_by.name', $admin->name);

        $this->assertNotNull(
            $this->withHeader('Authorization', "Bearer {$adminToken}")
                ->getJson("/api/admin/users/{$student->id}")
                ->json('data.deactivated_at'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Every login path refuses a deactivated account
    // ─────────────────────────────────────────────────────────────────────────

    public function test_password_login_is_refused(): void
    {
        $this->deactivated(['email' => 'zak@example.test']);

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/login', ['email' => 'zak@example.test', 'password' => 'password']),
        );
    }

    public function test_email_code_login_is_refused_before_touching_the_account(): void
    {
        $user = $this->deactivated(['email' => 'zak@example.test', 'email_verified_at' => null]);
        $code = VerificationCode::generateFor('zak@example.test', 10)->code;

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/email/verify', ['email' => 'zak@example.test', 'code' => $code]),
        );

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_register_verified_is_refused_before_the_profile_write(): void
    {
        $user = $this->deactivated(['email' => 'zak@example.test', 'name' => 'Původní']);
        VerificationCode::generateFor('zak@example.test', 10)->markAsVerified();

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/register-verified', ['email' => 'zak@example.test', 'name' => 'Přepsané']),
        );

        $this->assertSame('Původní', $user->fresh()->name);
    }

    public function test_student_login_code_is_refused(): void
    {
        $code = StudentCodes::generate();
        $this->deactivated(['login_code_hash' => StudentCodes::hash($code)]);

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/resolve-code', ['code' => $code]),
        );
    }

    public function test_google_login_is_refused(): void
    {
        $this->deactivated(['email' => 'zak@example.test']);
        $mock = Mockery::mock(GoogleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn([
            'sub' => '1', 'email' => 'zak@example.test', 'email_verified' => true, 'name' => 'Žák',
        ]);
        $this->instance(GoogleIdTokenVerifier::class, $mock);

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/auth/google', ['id_token' => 'x']),
        );
    }

    public function test_apple_login_is_refused(): void
    {
        $this->deactivated(['email' => 'zak@example.test']);
        $mock = Mockery::mock(AppleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn([
            'sub' => '1', 'email' => 'zak@example.test', 'email_verified' => true,
        ]);
        $this->instance(AppleIdTokenVerifier::class, $mock);

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/auth/apple', ['identity_token' => 'x']),
        );
    }

    public function test_microsoft_login_is_refused(): void
    {
        $this->deactivated(['email' => 'zak@example.test']);
        $mock = Mockery::mock(MicrosoftIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn([
            'sub' => '1', 'email' => 'zak@example.test', 'name' => 'Žák',
        ]);
        $this->instance(MicrosoftIdTokenVerifier::class, $mock);

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/auth/microsoft', ['id_token' => 'x']),
        );
    }

    public function test_admin_panel_login_is_refused(): void
    {
        $this->deactivated(['email' => 'ucitel@example.test', 'role' => 'teacher']);
        $code = VerificationCode::generateFor('ucitel@example.test', 10)->code;

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/admin/auth/verify', ['email' => 'ucitel@example.test', 'code' => $code]),
        );
    }

    public function test_guest_reconnect_is_refused(): void
    {
        $this->deactivated(['is_guest' => true, 'email' => null, 'device_id' => 'device-1']);

        $this->assertRefusedAsDeactivated(
            $this->postJson('/api/guest/register', ['device_id' => 'device-1', 'name' => 'Host']),
        );
    }

    public function test_a_guest_is_not_folded_into_a_deactivated_account(): void
    {
        $this->deactivated(['email' => 'zak@example.test']);
        $guest = User::factory()->create(['is_guest' => true, 'email' => null]);
        $guestToken = $guest->createToken('auth-token')->plainTextToken;
        VerificationCode::generateFor('zak@example.test', 10)->markAsVerified();

        $this->assertRefusedAsDeactivated(
            $this->withHeader('Authorization', "Bearer {$guestToken}")
                ->postJson('/api/guest/claim', ['email' => 'zak@example.test', 'name' => 'Host']),
        );

        $this->assertNull($guest->fresh()->merged_into_user_id);
    }
}
