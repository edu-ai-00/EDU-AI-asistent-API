<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MiniGame;
use App\Models\MiniGamePlay;
use App\Models\UserStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MiniGameController extends Controller
{
    /**
     * List active mini games with this user's play entitlement.
     *
     * GET /api/mini-games
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $level = $this->levelFor($user->id);

        $games = MiniGame::active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Every game's plays for this user in a single query — entitlement is
        // computed in PHP from this map, so the listing stays at two queries
        // regardless of how many games exist.
        $playsByGame = MiniGamePlay::where('user_id', $user->id)
            ->whereIn('mini_game_id', $games->pluck('id'))
            ->get()
            ->groupBy('mini_game_id');

        $data = $games->map(fn (MiniGame $game) => $game->toClientArray(
            $level,
            $playsByGame->get($game->id, collect()),
        ));

        return response()->json([
            'data' => $data,
            'meta' => [
                'level' => $level,
            ],
        ]);
    }

    /**
     * Show a single active mini game with entitlement.
     *
     * GET /api/mini-games/{code}
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $game = MiniGame::active()->where('code', $code)->firstOrFail();

        return response()->json([
            'data' => $game->toClientArray($this->levelFor($user->id)),
        ]);
    }

    /**
     * Record a play and return the refreshed entitlement.
     *
     * The game engine itself ships with the app build and runs offline; this
     * endpoint is the online half — it is what actually spends an attempt and
     * starts the cooldown. Called when a run starts, not when it ends, so a
     * user who kills the app mid-game cannot farm free attempts.
     *
     * POST /api/mini-games/{code}/play
     */
    public function play(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'score' => 'sometimes|nullable|integer|min:0',
        ]);

        $user = $request->user();
        $level = $this->levelFor($user->id);
        $game = MiniGame::active()->where('code', $code)->firstOrFail();

        // Re-check inside a transaction: the client already knows the answer
        // from the listing, but that snapshot may be stale (another device,
        // or a cooldown that has not elapsed yet).
        $result = DB::transaction(function () use ($game, $user, $level, $validated) {
            $plays = MiniGamePlay::where('user_id', $user->id)
                ->where('mini_game_id', $game->id)
                ->lockForUpdate()
                ->get();

            $entitlement = $game->entitlementFor($level, $plays);

            if (! $entitlement['is_playable']) {
                return ['locked' => true, 'entitlement' => $entitlement];
            }

            $play = MiniGamePlay::create([
                'mini_game_id' => $game->id,
                'user_id' => $user->id,
                'played_at' => now(),
                'score' => $validated['score'] ?? null,
            ]);

            return ['locked' => false, 'play' => $play];
        });

        if ($result['locked']) {
            return response()->json([
                'message' => 'Mini game is not available right now.',
                'data' => [
                    'is_playable' => false,
                    'lock_reason' => $result['entitlement']['lock_reason'],
                    'attempts_left' => $result['entitlement']['attempts_left'],
                    'unlocks_at' => $result['entitlement']['unlocks_at']?->toIso8601String(),
                ],
            ], 409);
        }

        return response()->json([
            'data' => $game->toClientArray($level) + [
                // The client holds on to this and reports the final score
                // against it once the run ends.
                'play_id' => $result['play']->id,
            ],
        ]);
    }

    /**
     * Attach the final score to a run that has already been started.
     *
     * Split from [play] on purpose: play() is what spends the daily attempt,
     * so reporting a score must never go through it — a second call would
     * cost the user another attempt.
     *
     * Coins collected during the run are credited here too (BR-HK9828). The
     * `whereNull('score')` guard is what makes that safe: a run reports once,
     * so the same coins cannot be banked twice by replaying the call.
     *
     * PATCH /api/mini-games/plays/{play}/score
     */
    public function submitScore(Request $request, int $play): JsonResponse
    {
        $validated = $request->validate([
            'score' => 'required|integer|min:0',
            'coins' => 'sometimes|integer|min:0',
        ]);

        $user = $request->user();
        $coins = (int) ($validated['coins'] ?? 0);

        // Scoped to the caller: someone else's run is indistinguishable from
        // a run that does not exist.
        $updated = MiniGamePlay::where('id', $play)
            ->where('user_id', $user->id)
            ->whereNull('score')
            ->update([
                'score' => $validated['score'],
                'coins' => $coins,
            ]);

        if ($updated === 0) {
            $exists = MiniGamePlay::where('id', $play)
                ->where('user_id', $user->id)
                ->exists();

            // Already scored: a run reports once. Retrying with a better number
            // would turn the endpoint into a high-score editor.
            return $exists
                ? response()->json(['message' => 'This run already has a score.'], 409)
                : response()->json(['message' => 'Run not found.'], 404);
        }

        UserStats::creditCoins($user->id, $coins);

        return response()->json([
            'data' => [
                'play_id' => $play,
                'score' => $validated['score'],
                'coins' => $coins,
            ],
        ]);
    }

    /**
     * Resolve the user's level, defaulting to 1 when stats do not exist yet.
     */
    private function levelFor(int $userId): int
    {
        return UserStats::where('user_id', $userId)->value('level') ?? 1;
    }
}
