<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every trophy, goal and challenge a coin reward (BR-HK9828).
     *
     * Derived from the config already in the table rather than restated: the
     * v2 seed is 150 lines of Czech copy, and a hand-retyped v3 would drift
     * from it the first time a title changes. Only `coin_reward` is added —
     * every other field is carried over verbatim.
     *
     * The rate is one coin per ten XP, floored at one. That keeps the two
     * rewards proportionate to each other (10 XP → 1 coin, 500 XP → 50), and
     * it means an achievement worth having is never worth zero coins.
     */
    private const XP_PER_COIN = 10;

    public function up(): void
    {
        $latest = DB::table('gamification_configs')->orderByDesc('version')->first();

        // Nothing seeded yet (a fresh database whose seeders have not run).
        // The rewards belong to a config, so there is nothing to add them to.
        if (! $latest) {
            return;
        }

        $config = json_decode($latest->config, true);

        foreach (['trophies', 'goals', 'challenges'] as $group) {
            if (! isset($config[$group])) {
                continue;
            }

            $config[$group] = array_map(function (array $achievement) {
                $xp = (int) ($achievement['xp_reward'] ?? 0);
                $achievement['coin_reward'] = $xp > 0
                    ? max(1, (int) round($xp / self::XP_PER_COIN))
                    : 0;

                return $achievement;
            }, $config[$group]);
        }

        DB::table('gamification_configs')->insert([
            'version' => $latest->version + 1,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $latest = DB::table('gamification_configs')->orderByDesc('version')->first();

        if ($latest) {
            DB::table('gamification_configs')->where('version', $latest->version)->delete();
        }
    }
};
