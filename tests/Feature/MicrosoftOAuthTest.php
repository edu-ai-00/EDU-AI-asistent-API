<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvalidIdTokenException;
use App\Services\MicrosoftIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for POST /api/auth/microsoft — the Entra ID id_token exchange.
 * The verifier is mocked so tests drive the controller's email extraction
 * (email vs preferred_username) and find-or-create logic.
 */
class MicrosoftOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVerifierReturning(array $claims): void
    {
        $mock = Mockery::mock(MicrosoftIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andReturn($claims);
        $this->instance(MicrosoftIdTokenVerifier::class, $mock);
    }

    public function test_new_user_from_email_claim(): void
    {
        $this->fakeVerifierReturning([
            'tid' => 'tenant-guid',
            'email' => 'New.MS@example.com',
            'name' => 'MS User',
        ]);

        $response = $this->postJson('/api/auth/microsoft', ['id_token' => 'anything']);

        $response->assertStatus(201);
        $response->assertJson(['is_new_user' => true, 'token_type' => 'Bearer']);
        $this->assertDatabaseHas('users', [
            'email' => 'new.ms@example.com',
            'name' => 'MS User',
        ]);
    }

    public function test_falls_back_to_preferred_username_when_no_email(): void
    {
        // Work/school accounts often omit `email` and carry the UPN instead.
        $this->fakeVerifierReturning([
            'tid' => 'tenant-guid',
            'preferred_username' => 'student@school.edu',
            'name' => 'Student',
        ]);

        $response = $this->postJson('/api/auth/microsoft', ['id_token' => 'anything']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'student@school.edu']);
    }

    public function test_existing_user_logs_in(): void
    {
        $user = User::factory()->create([
            'email' => 'known.ms@example.com',
            'selected_subjects' => ['math'],
        ]);

        $this->fakeVerifierReturning([
            'tid' => 'tenant-guid',
            'email' => 'known.ms@example.com',
            'name' => 'Known',
        ]);

        $response = $this->postJson('/api/auth/microsoft', ['id_token' => 'anything']);

        $response->assertOk();
        $response->assertJson(['is_new_user' => false, 'profile_setup_required' => false]);
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_invalid_token_is_rejected(): void
    {
        $mock = Mockery::mock(MicrosoftIdTokenVerifier::class);
        $mock->shouldReceive('verify')->andThrow(new InvalidIdTokenException('bad'));
        $this->instance(MicrosoftIdTokenVerifier::class, $mock);

        $response = $this->postJson('/api/auth/microsoft', ['id_token' => 'forged']);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_id_token']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_email_identifier_is_rejected(): void
    {
        $this->fakeVerifierReturning([
            'tid' => 'tenant-guid',
            'preferred_username' => 'not-an-email',
            'name' => 'Nope',
        ]);

        $response = $this->postJson('/api/auth/microsoft', ['id_token' => 'anything']);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'email_unavailable']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_merged_account_cannot_log_in(): void
    {
        $target = User::factory()->create();
        User::factory()->create([
            'email' => 'merged.ms@example.com',
            'merged_into_user_id' => $target->id,
        ]);

        $this->fakeVerifierReturning([
            'tid' => 'tenant-guid',
            'email' => 'merged.ms@example.com',
            'name' => 'Merged',
        ]);

        $response = $this->postJson('/api/auth/microsoft', ['id_token' => 'anything']);

        $response->assertStatus(410);
        $response->assertJson(['merged_into' => $target->id]);
    }

    public function test_id_token_is_required(): void
    {
        $response = $this->postJson('/api/auth/microsoft', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('id_token');
    }
}
