<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The EDUX coin wallet on /api/user/stats (BR-HK9828).
 *
 * The app awards coins locally so it keeps working on a train, then pushes the
 * new totals through this endpoint. These pin down what the server accepts
 * back — the whole point of the wallet is that the number the Achievements
 * screen shows survives a reinstall.
 */
class UserStatsCoinsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    public function test_a_fresh_wallet_reads_as_zero(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $response = $this->actingAsUser($user)->getJson('/api/user/stats');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('stats.coins_earned'));
        $this->assertSame(0, $response->json('stats.coins_balance'));
    }

    public function test_the_client_can_push_its_coin_totals(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        UserStats::create(['user_id' => $user->id]);

        $response = $this->actingAsUser($user)->putJson('/api/user/stats', [
            'coins_earned' => 40,
            'coins_balance' => 40,
        ]);

        $response->assertStatus(200);
        $this->assertSame(40, $response->json('stats.coins_earned'));
        $this->assertDatabaseHas('user_stats', [
            'user_id' => $user->id,
            'coins_earned' => 40,
            'coins_balance' => 40,
        ]);
    }

    public function test_a_stale_client_cannot_shrink_the_wallet(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        UserStats::create([
            'user_id' => $user->id,
            'coins_earned' => 100,
            'coins_balance' => 100,
        ]);

        // A device that synced before a profile merge — or one that has been
        // offline while another has been earning — reports a smaller total.
        $response = $this->actingAsUser($user)->putJson('/api/user/stats', [
            'coins_earned' => 25,
            'coins_balance' => 25,
        ]);

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('stats.coins_earned'));
        $this->assertSame(100, $response->json('stats.coins_balance'));
    }

    public function test_negative_coin_totals_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        UserStats::create(['user_id' => $user->id]);

        $this->actingAsUser($user)
            ->putJson('/api/user/stats', ['coins_earned' => -5])
            ->assertStatus(422);
    }

    public function test_crediting_coins_creates_stats_that_do_not_exist_yet(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        UserStats::creditCoins($user->id, 7);

        $this->assertDatabaseHas('user_stats', [
            'user_id' => $user->id,
            'coins_earned' => 7,
            'coins_balance' => 7,
        ]);
    }
}
