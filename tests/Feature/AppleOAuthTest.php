<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppleIdTokenVerifier;
use App\Services\InvalidIdTokenException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for POST /api/auth/apple — the Apple identity-token exchange.
 * The verifier is swapped for a double so tests drive the controller's
 * find-or-create + token issuance against known claim sets.
 */
class AppleOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVerifierReturning(array $claims): void
    {
        $mock = Mockery::mock(AppleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn($claims);
        $this->instance(AppleIdTokenVerifier::class, $mock);
    }

    public function test_new_user_is_created_with_name_from_body(): void
    {
        // Apple sends the name only on first sign-in, in the request body.
        $this->fakeVerifierReturning([
            'sub' => '001234.abcdef',
            'email' => 'New.Apple@example.com',
            'email_verified' => true,
        ]);

        $response = $this->postJson('/api/auth/apple', [
            'identity_token' => 'anything',
            'user' => ['name' => 'Apple User'],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'is_new_user' => true,
            'profile_setup_required' => true,
            'token_type' => 'Bearer',
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', [
            'email' => 'new.apple@example.com',
            'name' => 'Apple User',
        ]);
    }

    public function test_returning_user_logs_in_without_name_in_body(): void
    {
        $user = User::factory()->create([
            'email' => 'known.apple@example.com',
            'selected_subjects' => ['math'],
        ]);

        // No 'user' in body — Apple omits the name on repeat sign-ins.
        $this->fakeVerifierReturning([
            'sub' => '001234.abcdef',
            'email' => 'known.apple@example.com',
            'email_verified' => true,
        ]);

        $response = $this->postJson('/api/auth/apple', ['identity_token' => 'anything']);

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
        $mock = Mockery::mock(AppleIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andThrow(new InvalidIdTokenException('bad signature'));
        $this->instance(AppleIdTokenVerifier::class, $mock);

        $response = $this->postJson('/api/auth/apple', ['identity_token' => 'forged']);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_id_token']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->fakeVerifierReturning([
            'sub' => '7',
            'email' => 'unverified.apple@example.com',
            'email_verified' => false,
        ]);

        $response = $this->postJson('/api/auth/apple', ['identity_token' => 'anything']);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'email_unverified']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_merged_account_cannot_log_in(): void
    {
        $target = User::factory()->create();
        User::factory()->create([
            'email' => 'merged.apple@example.com',
            'merged_into_user_id' => $target->id,
        ]);

        $this->fakeVerifierReturning([
            'sub' => '9',
            'email' => 'merged.apple@example.com',
            'email_verified' => true,
        ]);

        $response = $this->postJson('/api/auth/apple', ['identity_token' => 'anything']);

        $response->assertStatus(410);
        $response->assertJson(['merged_into' => $target->id]);
    }

    public function test_identity_token_is_required(): void
    {
        $response = $this->postJson('/api/auth/apple', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('identity_token');
    }
}
