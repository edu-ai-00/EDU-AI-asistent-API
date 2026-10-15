<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use App\Services\InvalidIdTokenException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for POST /api/auth/google — the Google id_token exchange.
 *
 * The real verifier hits Google's JWKS; here we swap it for a double so the
 * tests exercise the controller's find-or-create + token issuance logic
 * against known claim sets.
 */
class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    /** Bind a verifier double that returns the given claims for any token. */
    private function fakeVerifierReturning(array $claims): void
    {
        $mock = Mockery::mock(GoogleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn($claims);
        $this->instance(GoogleIdTokenVerifier::class, $mock);
    }

    public function test_new_user_is_created_and_gets_a_token(): void
    {
        $this->fakeVerifierReturning([
            'sub' => '1234567890',
            'email' => 'New.User@example.com',
            'email_verified' => true,
            'name' => 'New User',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'anything']);

        $response->assertStatus(201);
        $response->assertJson([
            'is_new_user' => true,
            'profile_setup_required' => true,
            'token_type' => 'Bearer',
        ]);
        $this->assertNotEmpty($response->json('token'));

        // Email is normalised to lowercase on create.
        $this->assertDatabaseHas('users', [
            'email' => 'new.user@example.com',
            'name' => 'New User',
        ]);
    }

    public function test_an_sso_account_is_not_re_onboarded_on_every_login(): void
    {
        // The reported bug (BR-YXVMKY): this used to be answered with
        // "does the account have selected_subjects", and nothing on the SSO
        // path ever writes that column — so an account created by SSO was
        // sent back through the onboarding on every single sign-in.
        User::factory()->create([
            'email' => 'sso@example.com',
            'selected_subjects' => null,
        ]);

        $this->fakeVerifierReturning([
            'sub' => '7',
            'email' => 'sso@example.com',
            'email_verified' => true,
            'name' => 'Returning',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertOk()
            ->assertJsonPath('profile_setup_required', false);
    }

    public function test_existing_user_logs_in_without_profile_setup(): void
    {
        $user = User::factory()->create([
            'email' => 'known@example.com',
            'selected_subjects' => ['math'],
        ]);

        $this->fakeVerifierReturning([
            'sub' => '42',
            'email' => 'known@example.com',
            'email_verified' => true,
            'name' => 'Known',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'anything']);

        $response->assertOk();
        $response->assertJson([
            'is_new_user' => false,
            'profile_setup_required' => false,
        ]);
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Sign-in only: the client asks whether this credential already has an
     * account without creating one, so a returning user is never put through
     * the age gate and a minor's account never exists before consent
     * (BR-VTNTMQ).
     */
    public function test_unknown_email_is_not_registered_when_registration_is_disallowed(): void
    {
        $this->fakeVerifierReturning([
            'sub' => '55',
            'email' => 'stranger@example.com',
            'email_verified' => true,
            'name' => 'Stranger',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'anything',
            'allow_registration' => false,
        ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => 'registration_required']);
        $this->assertNull($response->json('token'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_user_still_signs_in_when_registration_is_disallowed(): void
    {
        $user = User::factory()->create([
            'email' => 'known@example.com',
            'selected_subjects' => ['math'],
        ]);

        $this->fakeVerifierReturning([
            'sub' => '56',
            'email' => 'known@example.com',
            'email_verified' => true,
            'name' => 'Known',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'anything',
            'allow_registration' => false,
        ]);

        $response->assertOk();
        $response->assertJson(['is_new_user' => false]);
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertNotEmpty($response->json('token'));
    }

    /** Older app builds send no flag at all and must keep registering. */
    public function test_registration_is_allowed_by_default(): void
    {
        $this->fakeVerifierReturning([
            'sub' => '57',
            'email' => 'default@example.com',
            'email_verified' => true,
            'name' => 'Default',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'default@example.com']);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $mock = Mockery::mock(GoogleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andThrow(new InvalidIdTokenException('bad signature'));
        $this->instance(GoogleIdTokenVerifier::class, $mock);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'forged']);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_id_token']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->fakeVerifierReturning([
            'sub' => '7',
            'email' => 'unverified@example.com',
            'email_verified' => false,
            'name' => 'Nope',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'anything']);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'email_unverified']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_merged_account_cannot_log_in(): void
    {
        $target = User::factory()->create();
        User::factory()->create([
            'email' => 'merged@example.com',
            'merged_into_user_id' => $target->id,
        ]);

        $this->fakeVerifierReturning([
            'sub' => '9',
            'email' => 'merged@example.com',
            'email_verified' => true,
            'name' => 'Merged',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'anything']);

        $response->assertStatus(410);
        $response->assertJson(['merged_into' => $target->id]);
    }

    public function test_id_token_is_required(): void
    {
        $response = $this->postJson('/api/auth/google', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('id_token');
    }
}
