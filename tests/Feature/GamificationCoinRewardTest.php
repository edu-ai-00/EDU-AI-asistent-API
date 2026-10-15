<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coin rewards on the served gamification config (BR-HK9828).
 *
 * The v3 seed derives itself from whatever config is already in the table, so
 * what matters is not the literal numbers but that every achievement comes
 * back with a `coin_reward` the app can read, proportionate to its XP.
 */
class GamificationCoinRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_achievement_carries_a_coin_reward(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/gamification/config');

        $response->assertStatus(200);

        $achievements = collect($response->json('trophies'))
            ->merge($response->json('goals'))
            ->merge($response->json('challenges'));

        $this->assertNotEmpty($achievements, 'The seeded config should carry achievements.');

        foreach ($achievements as $achievement) {
            $this->assertArrayHasKey('coin_reward', $achievement, "{$achievement['id']} has no coin reward.");

            $expected = $achievement['xp_reward'] > 0
                ? max(1, (int) round($achievement['xp_reward'] / 10))
                : 0;

            $this->assertSame($expected, $achievement['coin_reward'], "{$achievement['id']} is priced wrong.");
        }
    }

    public function test_the_rest_of_the_config_is_carried_over_untouched(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $token = $user->createToken('test')->plainTextToken;

        $config = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/gamification/config')
            ->json();

        // The seed rewrites one key per achievement; losing the copy or the
        // level ladder in the process is the failure worth catching.
        $this->assertNotEmpty($config['levels']);
        $this->assertNotEmpty($config['trophies'][0]['title']);
        $this->assertNotEmpty($config['trophies'][0]['condition']);
    }
}
