<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gamification_configs')->insert([
            'version' => 1,
            'config' => json_encode([
                'levels' => [
                    ['level' => 1, 'xp_required' => 0, 'title' => 'Začátečník', 'icon' => "\u{1F331}"],
                    ['level' => 2, 'xp_required' => 500, 'title' => 'Učeň', 'icon' => "\u{1F4D6}"],
                    ['level' => 3, 'xp_required' => 1000, 'title' => 'Student', 'icon' => "\u{1F393}"],
                    ['level' => 5, 'xp_required' => 2500, 'title' => 'Pokročilý', 'icon' => "\u{2B50}"],
                    ['level' => 10, 'xp_required' => 5000, 'title' => 'Mistr', 'icon' => "\u{1F3C6}"],
                ],
                'trophies' => [
                    [
                        'id' => 'first_lesson',
                        'title' => 'První lekce',
                        'description' => 'Dokonči svou první lekci',
                        'icon' => "\u{1F393}",
                        'xp_reward' => 10,
                        'condition' => ['type' => 'lessons_completed', 'value' => 1],
                    ],
                    [
                        'id' => 'streak_7',
                        'title' => 'Týdenní série',
                        'description' => 'Uč se 7 dní po sobě',
                        'icon' => "\u{1F525}",
                        'xp_reward' => 25,
                        'condition' => ['type' => 'streak_days', 'value' => 7],
                    ],
                    [
                        'id' => 'xp_500',
                        'title' => 'Sběratel bodů',
                        'description' => 'Získej 500 XP',
                        'icon' => "\u{26A1}",
                        'xp_reward' => 50,
                        'condition' => ['type' => 'total_xp', 'value' => 500],
                    ],
                    [
                        'id' => 'quiz_master',
                        'title' => 'Kvízový mistr',
                        'description' => 'Dokonči 5 kvízů',
                        'icon' => "\u{1F9E0}",
                        'xp_reward' => 30,
                        'condition' => ['type' => 'quizzes_completed', 'value' => 5],
                    ],
                    [
                        'id' => 'perfect_score',
                        'title' => 'Bezchybný',
                        'description' => 'Získej 100% v kvízu',
                        'icon' => "\u{1F48E}",
                        'xp_reward' => 20,
                        'condition' => ['type' => 'perfect_quizzes', 'value' => 1],
                    ],
                ],
                'goals' => [
                    [
                        'id' => 'xp_100',
                        'title' => 'Získat 100 XP',
                        'description' => 'Sbírej body za lekce a cvičení',
                        'icon' => "\u{26A1}",
                        'xp_reward' => 10,
                        'condition' => ['type' => 'total_xp', 'value' => 100],
                    ],
                    [
                        'id' => 'xp_1000',
                        'title' => 'Získat 1000 XP',
                        'description' => 'Cesta k tisícovce',
                        'icon' => "\u{26A1}",
                        'xp_reward' => 100,
                        'condition' => ['type' => 'total_xp', 'value' => 1000],
                    ],
                    [
                        'id' => 'courses_3',
                        'title' => 'Dokončit 3 kurzy',
                        'description' => 'Projdi kurzy od začátku do konce',
                        'icon' => "\u{1F4DA}",
                        'xp_reward' => 50,
                        'condition' => ['type' => 'courses_completed', 'value' => 3],
                    ],
                ],
                'challenges' => [
                    [
                        'id' => 'daily_3_blocks',
                        'title' => '3 bloky za den',
                        'description' => 'Dokonči 3 bloky v jednom dni',
                        'type' => 'daily',
                        'icon' => "\u{1F3AF}",
                        'xp_reward' => 15,
                        'condition' => ['type' => 'daily_blocks_completed', 'value' => 3],
                    ],
                    [
                        'id' => 'weekly_50xp',
                        'title' => '50 XP za týden',
                        'description' => 'Získej 50 XP tento týden',
                        'type' => 'weekly',
                        'icon' => "\u{1F4C8}",
                        'xp_reward' => 25,
                        'condition' => ['type' => 'weekly_xp', 'value' => 50],
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('gamification_configs')->where('version', 1)->delete();
    }
};
