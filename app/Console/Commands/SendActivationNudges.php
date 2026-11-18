<?php

namespace App\Console\Commands;

use App\Models\MiniGame;
use App\Models\MiniGamePlay;
use App\Models\User;
use App\Notifications\LearningActivity;
use App\Notifications\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * activation.daily (NOTIF-7 E, spec §3.5) — daily at 15:30.
 *
 * For every student who used the app within the last 14 days
 * (personal_access_tokens.last_used_at) and has not learned yet today
 * (their local day), pick one suggestion:
 *
 *   practice  they have due practice cards (due_date ≤ now)
 *   quiz      they have at least one course in their library
 *   game      an active mini game is playable for them right now
 *
 * Of the candidates, the one whose own last activity (last practice review /
 * quiz attempt / game play) is oldest wins — never counts as oldest; ties go
 * in the order quiz, practice, game. No candidate, no event. The one-a-day
 * cap is the service's (category_daily_caps.aktivace); a second run the
 * same day is deduplicated on activation:{suggestion}:{local date}.
 */
class SendActivationNudges extends Command
{
    protected $signature = 'notifications:activation';

    protected $description = 'Emit activation.daily (quiz / practice / game suggestion) for students idle today';

    private const CHUNK = 500;

    /** Tie-break order — the catalog's enum order. */
    private const ORDER = ['quiz', 'practice', 'game'];

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays(max(1, (int) config('notifications.activation_active_within_days', 14)));

        $games = MiniGame::active()->orderBy('sort_order')->orderBy('id')->get();
        $emitted = 0;

        LearningActivity::eligibleStudents()
            ->whereExists(function ($q) use ($since) {
                $q->select(DB::raw(1))
                    ->from('personal_access_tokens')
                    ->whereColumn('personal_access_tokens.tokenable_id', 'users.id')
                    ->where('personal_access_tokens.tokenable_type', User::class)
                    ->where('personal_access_tokens.last_used_at', '>=', $since);
            })
            ->select('users.id')
            ->chunkById(self::CHUNK, function (Collection $users) use ($now, $games, &$emitted) {
                $emitted += $this->processChunk($users->pluck('id')->map(fn ($id) => (int) $id)->all(), $now, $games);
            }, 'users.id', 'id');

        $this->info("Activation: {$emitted} student(s) nudged.");

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $userIds
     * @param  Collection<int, MiniGame>  $games
     */
    private function processChunk(array $userIds, CarbonImmutable $now, Collection $games): int
    {
        $timezones = LearningActivity::timezones($userIds);

        // Not active today, in each student's own local day.
        $idle = [];
        foreach (collect($timezones)->groupBy(fn ($tz) => $tz, true) as $timezone => $group) {
            $ids = array_keys($group->all());
            $active = LearningActivity::activeSince($ids, $now->setTimezone($timezone)->startOfDay());

            foreach ($ids as $id) {
                if (!isset($active[$id])) {
                    $idle[] = $id;
                }
            }
        }

        if ($idle === []) {
            return 0;
        }

        $due = DB::table('practice_cards')
            ->whereIn('user_id', $idle)
            ->where('is_active', true)
            ->where('due_date', '<=', $now)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as due')
            ->pluck('due', 'user_id');

        $withCourses = DB::table('user_courses')
            ->whereIn('user_id', $idle)
            ->distinct()
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true]);

        $playable = $this->playableGames($idle, $games, $now);

        $lastPractice = LearningActivity::latestBy('practice_review_logs', 'reviewed_at', $idle);
        $lastQuiz = LearningActivity::latestBy('quiz_attempts', 'created_at', $idle);
        $lastGame = LearningActivity::latestBy('mini_game_plays', 'played_at', $idle);
        $lastAny = LearningActivity::lastActivity($idle);

        // fields json|local date => [user ids]
        $groups = [];

        foreach ($idle as $userId) {
            $candidates = [];

            if ((int) ($due[$userId] ?? 0) > 0) {
                $candidates['practice'] = $lastPractice[$userId] ?? null;
            }
            if (isset($withCourses[$userId])) {
                $candidates['quiz'] = $lastQuiz[$userId] ?? null;
            }
            if (isset($playable[$userId])) {
                $candidates['game'] = $lastGame[$userId] ?? null;
            }

            $suggestion = self::pick($candidates);
            if ($suggestion === null) {
                continue;
            }

            $fields = ['suggestion' => $suggestion];
            if ((int) ($due[$userId] ?? 0) > 0) {
                $fields['due_count'] = (int) $due[$userId];
            }
            if ($suggestion === 'game') {
                $fields['game_name'] = $playable[$userId];
            }
            if (isset($lastAny[$userId])) {
                $fields['days_since_activity'] = max(0, LearningActivity::localDaysBetween($lastAny[$userId], $now, $timezones[$userId]));
            }

            $date = $now->setTimezone($timezones[$userId])->toDateString();
            $groups[json_encode($fields) . '|' . $date][] = $userId;
        }

        $emitted = 0;

        foreach ($groups as $key => $ids) {
            $separator = strrpos($key, '|');
            $fields = json_decode(substr($key, 0, $separator), true);
            $date = substr($key, $separator + 1);

            if (NotificationEvents::emit('activation.daily', $fields, $ids, 'activation:' . $fields['suggestion'] . ':' . $date)) {
                $emitted += count($ids);
            }
        }

        return $emitted;
    }

    /**
     * The candidate with the oldest own last activity (never = oldest);
     * ties in ORDER.
     *
     * @param  array<string, ?CarbonImmutable>  $candidates
     */
    public static function pick(array $candidates): ?string
    {
        $best = null;
        $bestAt = null;

        foreach (self::ORDER as $suggestion) {
            if (!array_key_exists($suggestion, $candidates)) {
                continue;
            }

            $at = $candidates[$suggestion];

            if ($best === null) {
                [$best, $bestAt] = [$suggestion, $at];

                continue;
            }

            // A never-done candidate beats any done one; among two dated ones the older wins.
            if ($bestAt !== null && ($at === null || $at->lessThan($bestAt))) {
                [$best, $bestAt] = [$suggestion, $at];
            }
        }

        return $best;
    }

    /**
     * The first playable active game (by sort order) per student, as its
     * title — MiniGame::entitlementFor() decides, with only the plays that
     * can still matter (today's, and those inside the longest cooldown).
     *
     * @param  list<int>  $userIds
     * @param  Collection<int, MiniGame>  $games
     * @return array<int, string>
     */
    private function playableGames(array $userIds, Collection $games, CarbonImmutable $now): array
    {
        if ($games->isEmpty() || $userIds === []) {
            return [];
        }

        $levels = DB::table('user_stats')->whereIn('user_id', $userIds)->pluck('level', 'user_id');

        $longestCooldown = (int) $games->max('cooldown_hours');
        $from = $now->startOfDay();
        if ($longestCooldown > 0 && $now->subHours($longestCooldown)->lessThan($from)) {
            $from = $now->subHours($longestCooldown);
        }

        $plays = MiniGamePlay::whereIn('user_id', $userIds)
            ->whereIn('mini_game_id', $games->pluck('id'))
            ->where('played_at', '>=', $from)
            ->get()
            ->groupBy(fn (MiniGamePlay $p) => $p->user_id . ':' . $p->mini_game_id);

        $out = [];
        foreach ($userIds as $userId) {
            $level = (int) ($levels[$userId] ?? 1);

            foreach ($games as $game) {
                $entitlement = $game->entitlementFor($level, $plays->get($userId . ':' . $game->id, collect()));

                if ($entitlement['is_playable']) {
                    $out[$userId] = (string) $game->title;

                    break;
                }
            }
        }

        return $out;
    }
}
