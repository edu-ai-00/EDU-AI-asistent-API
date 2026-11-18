<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The entrance-exam banner, moved out of the app's code (BR-SAWX2U).
 *
 * Seeded with the texts, colour, illustration and XP thresholds the
 * hard-coded `_buildJpzSection` used, so the dashboard looks the same the
 * moment this ships and the client retunes the bands in the admin instead of
 * in a release. The old thresholds are kept on purpose: the report asks for
 * 31–150 XP where the code had a switch at 100, and which one is right is the
 * client's call, not a guess in a migration.
 */
return new class extends Migration
{
    private const BANNERS = [
        [
            'key' => 'system.jpz_intro',
            'name' => 'Přijímačky – úvodní kurz',
            'position' => 10,
            'badge' => 'PŘIJÍMAČKY',
            'title' => 'Uvažuješ o přijímačkách?',
            'body' => "Projdi si úvod k jednotné\npřijímací zkoušce",
            'link' => '/field-course/EDU_JPZ_INTRO',
            'min_xp' => null,
            'max_xp' => 100,
        ],
        [
            'key' => 'system.jpz_cermatik',
            'name' => 'Přijímačky – Cermatík',
            'position' => 20,
            'badge' => 'PŘIJÍMAČKY',
            'title' => 'Uvažuješ o přijímačkách?',
            'body' => "Zeptej se Cermatíka na cokoli\nkolem přijímaček",
            'link' => '/chat/cermatik',
            'min_xp' => 101,
            'max_xp' => null,
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::BANNERS as $banner) {
            DB::table('dashboard_banners')->updateOrInsert(
                ['key' => $banner['key']],
                $banner + [
                    'is_active' => true,
                    'is_system' => true,
                    'accent' => 'primary',
                    'illustration' => 'news',
                    'target_role' => 'all',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('dashboard_banners')
            ->whereIn('key', array_column(self::BANNERS, 'key'))
            ->delete();
    }
};
