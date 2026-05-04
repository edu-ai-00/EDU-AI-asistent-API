<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gamification_configs')->insert([
            'version' => 2,
            'config' => json_encode([
                'levels' => [
                    ['level' => 1, 'xp_required' => 0, 'title' => 'Začátečník', 'icon' => '🌱'],
                    ['level' => 2, 'xp_required' => 500, 'title' => 'Učeň', 'icon' => '📚'],
                    ['level' => 3, 'xp_required' => 1000, 'title' => 'Student', 'icon' => '🎓'],
                    ['level' => 4, 'xp_required' => 1500, 'title' => 'Pokročilý', 'icon' => '⭐'],
                    ['level' => 5, 'xp_required' => 2000, 'title' => 'Expert', 'icon' => '💡'],
                    ['level' => 6, 'xp_required' => 2500, 'title' => 'Mistr', 'icon' => '👑'],
                    ['level' => 7, 'xp_required' => 3000, 'title' => 'Guru', 'icon' => '🧙'],
                    ['level' => 8, 'xp_required' => 4000, 'title' => 'Legenda', 'icon' => '🏆'],
                    ['level' => 9, 'xp_required' => 5000, 'title' => 'Génius', 'icon' => '🚀'],
                    ['level' => 10, 'xp_required' => 7500, 'title' => 'Nedostižný', 'icon' => '🌌'],
                ],
                'trophies' => [
                    [
                        'id' => 'trophy_first_lesson',
                        'title' => 'První lekce',
                        'description' => 'Dokonči svou první lekci',
                        'icon' => '🏆',
                        'xp_reward' => 25,
                        'condition' => ['type' => 'lessons_completed', 'value' => 1],
                        'category' => 'trophy',
                    ],
                    [
                        'id' => 'trophy_five_lessons',
                        'title' => 'Pilný student',
                        'description' => 'Dokonči 5 lekcí',
                        'icon' => '📚',
                        'xp_reward' => 50,
                        'condition' => ['type' => 'lessons_completed', 'value' => 5],
                        'category' => 'trophy',
                    ],
                    [
                        'id' => 'trophy_first_quiz',
                        'title' => 'Kvízový nováček',
                        'description' => 'Dokonči svůj první kvíz',
                        'icon' => '🧠',
                        'xp_reward' => 30,
                        'condition' => ['type' => 'quizzes_completed', 'value' => 1],
                        'category' => 'trophy',
                    ],
                    [
                        'id' => 'trophy_first_course',
                        'title' => 'Mistrovský kousek',
                        'description' => 'Dokonči celý kurz',
                        'icon' => '🧬',
                        'xp_reward' => 100,
                        'condition' => ['type' => 'courses_completed', 'value' => 1],
                        'category' => 'trophy',
                    ],
                    [
                        'id' => 'trophy_week_streak',
                        'title' => 'Týdenní série',
                        'description' => 'Uč se 7 dní v řadě',
                        'icon' => '🔥',
                        'xp_reward' => 50,
                        'condition' => ['type' => 'streak_days', 'value' => 7],
                        'category' => 'trophy',
                    ],
                    [
                        'id' => 'trophy_month_streak',
                        'title' => 'Měsíční série',
                        'description' => 'Uč se 30 dní v řadě',
                        'icon' => '💪',
                        'xp_reward' => 150,
                        'condition' => ['type' => 'streak_days', 'value' => 30],
                        'category' => 'trophy',
                    ],
                ],
                'goals' => [
                    [
                        'id' => 'goal_xp_100',
                        'title' => 'Získat 100 XP',
                        'description' => 'Sbírej body za dokončené lekce a cvičení',
                        'icon' => '⚡',
                        'xp_reward' => 10,
                        'condition' => ['type' => 'total_xp', 'value' => 100],
                        'category' => 'goal',
                    ],
                    [
                        'id' => 'goal_xp_500',
                        'title' => 'Získat 500 XP',
                        'description' => 'Sbírej body za dokončené lekce a cvičení',
                        'icon' => '⚡',
                        'xp_reward' => 50,
                        'condition' => ['type' => 'total_xp', 'value' => 500],
                        'category' => 'goal',
                    ],
                    [
                        'id' => 'goal_xp_1000',
                        'title' => 'Získat 1000 XP',
                        'description' => 'Sbírej body za dokončené lekce a cvičení',
                        'icon' => '⚡',
                        'xp_reward' => 100,
                        'condition' => ['type' => 'total_xp', 'value' => 1000],
                        'category' => 'goal',
                    ],
                    [
                        'id' => 'goal_streak_3',
                        'title' => 'Série 3 dní',
                        'description' => 'Uč se každý den po sobě',
                        'icon' => '🔥',
                        'xp_reward' => 15,
                        'condition' => ['type' => 'streak_days', 'value' => 3],
                        'category' => 'goal',
                    ],
                    [
                        'id' => 'goal_streak_14',
                        'title' => 'Série 14 dní',
                        'description' => 'Uč se každý den po sobě',
                        'icon' => '🔥',
                        'xp_reward' => 70,
                        'condition' => ['type' => 'streak_days', 'value' => 14],
                        'category' => 'goal',
                    ],
                    [
                        'id' => 'goal_level_3',
                        'title' => 'Dosáhnout úrovně 3',
                        'description' => 'Každá úroveň = 500 XP',
                        'icon' => '⭐',
                        'xp_reward' => 50,
                        'condition' => ['type' => 'level', 'value' => 3],
                        'category' => 'goal',
                    ],
                    [
                        'id' => 'goal_lessons_10',
                        'title' => 'Dokonči 10 lekcí',
                        'description' => 'Učení dělá mistra',
                        'icon' => '📚',
                        'xp_reward' => 60,
                        'condition' => ['type' => 'lessons_completed', 'value' => 10],
                        'category' => 'goal',
                    ],
                ],
                'challenges' => [
                    [
                        'id' => 'challenge_xp_5000',
                        'title' => 'Získat 5000 XP',
                        'description' => 'Dokaž svou vytrvalost',
                        'icon' => '💎',
                        'xp_reward' => 500,
                        'condition' => ['type' => 'total_xp', 'value' => 5000],
                        'type' => 'lifetime',
                        'category' => 'challenge',
                    ],
                    [
                        'id' => 'challenge_courses_3',
                        'title' => 'Dokonči 3 kurzy',
                        'description' => 'Prozkoumej různá témata',
                        'icon' => '🚀',
                        'xp_reward' => 200,
                        'condition' => ['type' => 'courses_completed', 'value' => 3],
                        'type' => 'lifetime',
                        'category' => 'challenge',
                    ],
                    [
                        'id' => 'challenge_streak_60',
                        'title' => 'Série 60 dní',
                        'description' => 'Opravdová disciplína',
                        'icon' => '🌋',
                        'xp_reward' => 300,
                        'condition' => ['type' => 'streak_days', 'value' => 60],
                        'type' => 'lifetime',
                        'category' => 'challenge',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('gamification_configs')->where('version', 2)->delete();
    }
};
