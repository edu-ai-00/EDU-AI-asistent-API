<?php

namespace Database\Seeders;

use App\Models\MiniGame;
use Illuminate\Database\Seeder;

class MiniGameSeeder extends Seeder
{
    /**
     * Seed the mini games shown in the client listing.
     *
     * Keyed on `code` so re-running the seeder updates metadata in place and
     * never orphans the play history attached to a game.
     */
    public function run(): void
    {
        $games = [
            [
                'code' => 'sky_jumper',
                'title' => 'Sky Jumper',
                'perex' => 'Sbírej Edux mince a dostaň se co nejvýše. Ale pozor! Neuklouzni a nespadni. Natáčej zařízení do strany. Skáčeš automaticky.',
                'description' => null,
                'image_url' => null,
                'sensor' => 'gyroscope',
                'duration_minutes' => 4,
                'min_level' => 1,
                'daily_attempts' => 4,
                'cooldown_hours' => null,
                'setup' => null,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'big_skater',
                'title' => 'Big Skater',
                'perex' => 'Sbírej Edux mince a dostaň se co nejvýše. Ale pozor! Neuklouzni a nespadni. Natáčej zařízení do strany. Skáčeš automaticky.',
                'description' => null,
                'image_url' => null,
                'sensor' => 'gyroscope',
                'duration_minutes' => 6,
                'min_level' => 1,
                'daily_attempts' => 3,
                'cooldown_hours' => 4,
                'setup' => null,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'minigame_3',
                'title' => 'Minihra 3',
                'perex' => 'Sbírej Edux mince a dostaň se co nejvýše. Ale pozor! Neuklouzni a nespadni. Natáčej zařízení do strany. Skáčeš automaticky.',
                'description' => null,
                'image_url' => null,
                'sensor' => null,
                'duration_minutes' => null,
                'min_level' => 5,
                'daily_attempts' => null,
                'cooldown_hours' => null,
                'setup' => null,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];

        foreach ($games as $game) {
            MiniGame::updateOrCreate(['code' => $game['code']], $game);
        }
    }
}
