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
