<?php

namespace Tests\Feature;

use App\Models\MiniGame;
use App\Models\MiniGamePlay;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiniGameTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function makeStudent(int $level = 1): User
    {
        $user = User::factory()->create(['role' => 'student']);

        UserStats::create(['user_id' => $user->id, 'level' => $level]);

        return $user;
    }

    private function gameFrom(array $payload, string $code): ?array
    {
        return collect($payload)->firstWhere('code', $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Listing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inactive_games_are_hidden_from_the_listing(): void
    {
        MiniGame::factory()->create(['code' => 'live']);
        MiniGame::factory()->inactive()->create(['code' => 'hidden']);

        $response = $this->actingAsUser($this->makeStudent())->getJson('/api/mini-games');

        $response->assertStatus(200);
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('live', $codes);
        $this->assertNotContains('hidden', $codes);
    }

    public function test_games_are_ordered_by_sort_order(): void
    {
        MiniGame::factory()->create(['code' => 'second', 'sort_order' => 20]);
        MiniGame::factory()->create(['code' => 'first', 'sort_order' => 10]);

        $response = $this->actingAsUser($this->makeStudent())->getJson('/api/mini-games');

        $this->assertSame(
            ['first', 'second'],
            collect($response->json('data'))->pluck('code')->all(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Level gate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_game_below_required_level_is_locked(): void
    {
        MiniGame::factory()->create(['code' => 'locked', 'min_level' => 5]);

        $response = $this->actingAsUser($this->makeStudent(level: 3))->getJson('/api/mini-games');

        $game = $this->gameFrom($response->json('data'), 'locked');
        $this->assertFalse($game['is_playable']);
        $this->assertSame('level', $game['lock_reason']);
    }

    public function test_game_at_required_level_is_playable(): void
    {
        MiniGame::factory()->create(['code' => 'unlocked', 'min_level' => 5]);

        $response = $this->actingAsUser($this->makeStudent(level: 5))->getJson('/api/mini-games');

        $game = $this->gameFrom($response->json('data'), 'unlocked');
        $this->assertTrue($game['is_playable']);
        $this->assertNull($game['lock_reason']);
    }

    public function test_user_without_stats_row_is_treated_as_level_one(): void
    {
        MiniGame::factory()->create(['code' => 'gated', 'min_level' => 2]);
        $user = User::factory()->create(['role' => 'student']);

        $response = $this->actingAsUser($user)->getJson('/api/mini-games');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('meta.level'));
        $this->assertSame('level', $this->gameFrom($response->json('data'), 'gated')['lock_reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Daily attempts
    // ─────────────────────────────────────────────────────────────────────────

    public function test_attempts_left_counts_down_and_locks_at_zero(): void
    {
        $game = MiniGame::factory()->create(['code' => 'quota', 'daily_attempts' => 2]);
        $user = $this->makeStudent();

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now(),
        ]);

        $first = $this->gameFrom(
            $this->actingAsUser($user)->getJson('/api/mini-games')->json('data'),
            'quota',
        );
        $this->assertSame(1, $first['attempts_left']);
        $this->assertTrue($first['is_playable']);

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now(),
        ]);

        $second = $this->gameFrom(
            $this->actingAsUser($user)->getJson('/api/mini-games')->json('data'),
            'quota',
        );
        $this->assertSame(0, $second['attempts_left']);
        $this->assertFalse($second['is_playable']);
        $this->assertSame('attempts', $second['lock_reason']);
    }

    public function test_yesterdays_plays_do_not_count_against_todays_quota(): void
    {
        $game = MiniGame::factory()->create(['code' => 'quota', 'daily_attempts' => 1]);
        $user = $this->makeStudent();

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now()->subDay(),
        ]);

        $payload = $this->gameFrom(
            $this->actingAsUser($user)->getJson('/api/mini-games')->json('data'),
            'quota',
        );

        $this->assertTrue($payload['is_playable']);
        $this->assertSame(1, $payload['attempts_left']);
    }

    public function test_another_users_plays_do_not_affect_this_users_quota(): void
    {
        $game = MiniGame::factory()->create(['code' => 'quota', 'daily_attempts' => 1]);
        $mine = $this->makeStudent();
        $theirs = $this->makeStudent();

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $theirs->id,
            'played_at' => now(),
        ]);

        $payload = $this->gameFrom(
            $this->actingAsUser($mine)->getJson('/api/mini-games')->json('data'),
            'quota',
        );

        $this->assertTrue($payload['is_playable']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cooldown
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cooldown_locks_the_game_and_reports_when_it_unlocks(): void
    {
        $game = MiniGame::factory()->create(['code' => 'cooling', 'cooldown_hours' => 4]);
        $user = $this->makeStudent();

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now()->subHour(),
        ]);

        $payload = $this->gameFrom(
            $this->actingAsUser($user)->getJson('/api/mini-games')->json('data'),
            'cooling',
        );

        $this->assertFalse($payload['is_playable']);
        $this->assertSame('cooldown', $payload['lock_reason']);
        $this->assertNotNull($payload['unlocks_at']);
    }

    public function test_cooldown_expires(): void
    {
        $game = MiniGame::factory()->create(['code' => 'cooled', 'cooldown_hours' => 4]);
        $user = $this->makeStudent();

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now()->subHours(5),
        ]);

        $payload = $this->gameFrom(
            $this->actingAsUser($user)->getJson('/api/mini-games')->json('data'),
            'cooled',
        );

        $this->assertTrue($payload['is_playable']);
        $this->assertNull($payload['lock_reason']);
    }

    public function test_level_lock_wins_over_cooldown(): void
    {
        $game = MiniGame::factory()->create([
            'code' => 'both',
            'min_level' => 9,
            'cooldown_hours' => 4,
        ]);
        $user = $this->makeStudent(level: 1);

        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now(),
        ]);

        $payload = $this->gameFrom(
            $this->actingAsUser($user)->getJson('/api/mini-games')->json('data'),
            'both',
        );

        $this->assertSame('level', $payload['lock_reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Play endpoint
    // ─────────────────────────────────────────────────────────────────────────

    public function test_play_records_an_attempt_and_returns_fresh_state(): void
    {
        $game = MiniGame::factory()->create(['code' => 'playable', 'daily_attempts' => 2]);
        $user = $this->makeStudent();

        $response = $this->actingAsUser($user)->postJson('/api/mini-games/playable/play');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.attempts_left'));
        $this->assertDatabaseCount('mini_game_plays', 1);
    }

    public function test_play_is_rejected_when_the_game_is_locked(): void
    {
        MiniGame::factory()->create(['code' => 'gated', 'min_level' => 5]);
        $user = $this->makeStudent(level: 1);

        $response = $this->actingAsUser($user)->postJson('/api/mini-games/gated/play');

        $response->assertStatus(409);
        $this->assertSame('level', $response->json('data.lock_reason'));
        $this->assertDatabaseCount('mini_game_plays', 0);
    }

    public function test_play_on_an_inactive_game_returns_404(): void
    {
        MiniGame::factory()->inactive()->create(['code' => 'hidden']);

        $this->actingAsUser($this->makeStudent())
            ->postJson('/api/mini-games/hidden/play')
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Score submission
    // ─────────────────────────────────────────────────────────────────────────

    public function test_play_returns_the_id_of_the_recorded_run(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();

        $response = $this->actingAsUser($user)->postJson('/api/mini-games/playable/play');

        $playId = $response->json('data.play_id');
        $this->assertNotNull($playId);
        $this->assertDatabaseHas('mini_game_plays', ['id' => $playId, 'user_id' => $user->id]);
    }

    public function test_score_is_stored_on_the_run_it_belongs_to(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        $response = $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 23000]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('mini_game_plays', ['id' => $playId, 'score' => 23000]);
    }

    public function test_submitting_a_score_does_not_spend_another_attempt(): void
    {
        MiniGame::factory()->create(['code' => 'playable', 'daily_attempts' => 2]);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 100]);

        $this->assertDatabaseCount('mini_game_plays', 1);
    }

    public function test_a_run_keeps_its_first_score(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 100]);
        $response = $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 999999]);

        $response->assertStatus(409);
        $this->assertDatabaseHas('mini_game_plays', ['id' => $playId, 'score' => 100]);
    }

    public function test_a_score_cannot_be_written_to_someone_elses_run(): void
    {
        $game = MiniGame::factory()->create(['code' => 'playable']);
        $owner = $this->makeStudent();
        $stranger = $this->makeStudent();

        // Built directly rather than through /play: the request that matters
        // here is the stranger's, and a single authenticated request per test
        // keeps Sanctum's per-guard user cache out of the way.
        $playId = MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $owner->id,
            'played_at' => now(),
        ])->id;

        $response = $this->actingAsUser($stranger)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 999]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('mini_game_plays', ['id' => $playId, 'score' => null]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Coins (BR-HK9828)
    //
    // The run is where EDUX coins are actually earned, so reporting it is also
    // what banks them. The wallet these credit is what the Achievements screen
    // reads back as "Mince EDUX".
    // ─────────────────────────────────────────────────────────────────────────

    public function test_collected_coins_are_credited_to_the_wallet(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 500, 'coins' => 12])
            ->assertStatus(200);

        $this->assertDatabaseHas('mini_game_plays', ['id' => $playId, 'coins' => 12]);
        $this->assertDatabaseHas('user_stats', [
            'user_id' => $user->id,
            'coins_earned' => 12,
            'coins_balance' => 12,
        ]);
    }

    public function test_coins_accumulate_across_runs(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();

        foreach ([4, 7] as $coins) {
            $playId = $this->actingAsUser($user)
                ->postJson('/api/mini-games/playable/play')
                ->json('data.play_id');

            $this->actingAsUser($user)
                ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 10, 'coins' => $coins]);
        }

        $this->assertDatabaseHas('user_stats', [
            'user_id' => $user->id,
            'coins_earned' => 11,
            'coins_balance' => 11,
        ]);
    }

    public function test_replaying_the_score_call_does_not_mint_more_coins(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 100, 'coins' => 9]);
        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 100, 'coins' => 9])
            ->assertStatus(409);

        $this->assertDatabaseHas('user_stats', ['user_id' => $user->id, 'coins_earned' => 9]);
    }

    public function test_a_run_reported_without_coins_credits_nothing(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        // An older app build reports the score alone. It must still succeed.
        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => 100])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_stats', ['user_id' => $user->id, 'coins_earned' => 0]);
    }

    public function test_a_negative_score_is_rejected(): void
    {
        MiniGame::factory()->create(['code' => 'playable']);
        $user = $this->makeStudent();
        $playId = $this->actingAsUser($user)
            ->postJson('/api/mini-games/playable/play')
            ->json('data.play_id');

        $this->actingAsUser($user)
            ->patchJson("/api/mini-games/plays/{$playId}/score", ['score' => -1])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seeding
    //
    // start.sh runs MiniGameSeeder on every deploy, because migrations alone
    // leave mini_games empty and the client then renders an empty listing no
    // matter what ships in the app. That only works while the seeder is
    // repeatable, which is what these pin down.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_seeding_twice_does_not_duplicate_a_game(): void
    {
        $this->seed(\Database\Seeders\MiniGameSeeder::class);
        $this->seed(\Database\Seeders\MiniGameSeeder::class);

        $this->assertSame(1, MiniGame::where('code', 'sky_jumper')->count());
    }

    public function test_reseeding_preserves_the_play_history_of_a_game(): void
    {
        $this->seed(\Database\Seeders\MiniGameSeeder::class);
        $game = MiniGame::where('code', 'sky_jumper')->firstOrFail();
        $user = $this->makeStudent();
        MiniGamePlay::create([
            'mini_game_id' => $game->id,
            'user_id' => $user->id,
            'played_at' => now(),
        ]);

        $this->seed(\Database\Seeders\MiniGameSeeder::class);

        // A seeder that deleted and recreated rows would orphan every play.
        $this->assertSame(
            $game->id,
            MiniGame::where('code', 'sky_jumper')->value('id'),
        );
        $this->assertDatabaseCount('mini_game_plays', 1);
    }

    public function test_the_seeded_listing_offers_sky_jumper(): void
    {
        $this->seed(\Database\Seeders\MiniGameSeeder::class);

        $payload = $this->actingAsUser($this->makeStudent())
            ->getJson('/api/mini-games')
            ->json('data');

        $game = $this->gameFrom($payload, 'sky_jumper');
        $this->assertNotNull($game, 'sky_jumper must reach the client listing');
        $this->assertTrue($game['is_playable']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Auth
    // ─────────────────────────────────────────────────────────────────────────

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/mini-games')->assertStatus(401);
    }
}
