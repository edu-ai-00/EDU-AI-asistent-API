<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for BR-CAQQW2: the shared-device banner must let a user
 * choose "this is my device" and stay logged in. That converts the session to
 * persistent via /api/auth/refresh with an explicit shared_device=false.
 *
 * Also guards the default behaviour: refresh with no param preserves the
 * current mode (used by automated token rotation).
 */
class SessionRefreshTest extends TestCase
{
    use RefreshDatabase;

    /** Issue a token annotated as a shared-device session, return plaintext. */
    private function sharedToken(User $user): string
    {
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes(30));
        $token->accessToken->forceFill([
            'shared_device' => true,
            'session_started_at' => now(),
        ])->save();

        return $token->plainTextToken;
    }

    public function test_refresh_converts_shared_session_to_persistent(): void
    {
        $user = User::factory()->create();
        $token = $this->sharedToken($user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/auth/refresh', ['shared_device' => false]);

        $response->assertOk();
        $response->assertJson(['shared_device' => false]);
        // Persistent sessions have no 8h cap anchor.
        $this->assertNull($response->json('session_started_at'));

        // The new token is persistent: ~30 days out, not ~30 minutes.
        $expiresAt = \Carbon\Carbon::parse($response->json('expires_at'));
        $this->assertTrue(
            $expiresAt->greaterThan(now()->addDays(29)),
            'Converted session should get a 30-day persistent token'
        );
    }

    public function test_refresh_without_param_preserves_shared_mode(): void
    {
        $user = User::factory()->create();
        $token = $this->sharedToken($user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/auth/refresh');

        $response->assertOk();
        $response->assertJson(['shared_device' => true]);
        $this->assertNotNull($response->json('session_started_at'));

        $expiresAt = \Carbon\Carbon::parse($response->json('expires_at'));
        $this->assertTrue(
            $expiresAt->lessThan(now()->addHour()),
            'Preserved shared session keeps the 30-minute token'
        );
    }
}
