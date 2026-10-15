<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-AYACEK — the catalogue every language reference points at.
 *
 * `courses.language` used to be a free-text column, so "cs", "CS" and "čeština"
 * could all coexist. Multilingual course variants need one shared vocabulary,
 * so the set of publishable languages becomes a table and uploads validate
 * against it.
 */
return new class extends Migration
{
    /**
     * Seeded here rather than in a seeder: the upload validator rejects any
     * language missing from this table, so an empty catalogue would break every
     * course upload on a fresh deploy.
     */
    private const DEFAULTS = [
        ['code' => 'cs', 'name' => 'Czech', 'native_name' => 'Čeština', 'sort_order' => 1],
        ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'sort_order' => 2],
        ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina', 'sort_order' => 3],
        ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'sort_order' => 4],
        ['code' => 'pl', 'name' => 'Polish', 'native_name' => 'Polski', 'sort_order' => 5],
        ['code' => 'uk', 'name' => 'Ukrainian', 'native_name' => 'Українська', 'sort_order' => 6],
    ];

    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('native_name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        DB::table('languages')->insert(array_map(
            fn (array $language) => $language + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::DEFAULTS,
        ));

        // Make the free-text values already in courses.language resolvable
        // against the catalogue, without rewriting anything we cannot map with
        // confidence — see normaliseExistingCourseLanguages().
        $this->normaliseExistingCourseLanguages();
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }

    /**
     * `courses.language` was free text, so production holds a mix of "cs",
     * "CS", "cs-CZ" and the odd "čeština". Uploads are about to validate
     * against the catalogue, so every existing value has to resolve.
     *
     * Two rules, both non-destructive:
     *   - a value we can map with confidence is rewritten to its canonical code
     *   - anything else is *added to the catalogue* as an inactive row rather
     *     than being overwritten, so no course is silently mislabelled. An
     *     admin activates or reassigns it later.
     * Only a blank language falls back to a default.
     */
    private function normaliseExistingCourseLanguages(): void
    {
        if (!Schema::hasTable('courses')) {
            return;
        }

        $aliases = [
            'čeština' => 'cs', 'cestina' => 'cs', 'czech' => 'cs', 'cz' => 'cs', 'cs-cz' => 'cs',
            'english' => 'en', 'eng' => 'en', 'en-us' => 'en', 'en-gb' => 'en',
            'slovenčina' => 'sk', 'slovencina' => 'sk', 'slovak' => 'sk', 'sk-sk' => 'sk',
            'deutsch' => 'de', 'german' => 'de', 'de-de' => 'de',
            'polski' => 'pl', 'polish' => 'pl', 'pl-pl' => 'pl',
            'українська' => 'uk', 'ukrainian' => 'uk', 'uk-ua' => 'uk',
        ];

        $known = array_column(self::DEFAULTS, 'code');

        DB::table('courses')->select('id', 'language')->orderBy('id')
            ->chunk(500, function ($courses) use ($aliases, $known) {
                foreach ($courses as $course) {
                    $raw = trim((string) $course->language);
                    $code = $aliases[mb_strtolower($raw)] ?? mb_strtolower($raw);

                    if ($code === '') {
                        $code = 'cs';
                    }

                    if (!in_array($code, $known, true)) {
                        $this->adoptUnknownLanguage($code);
                        $known[] = $code;
                    }

                    if ($code !== $raw) {
                        DB::table('courses')->where('id', $course->id)
                            ->update(['language' => $code]);
                    }
                }
            });
    }

    /**
     * Park an unrecognised language code in the catalogue, inactive, so the
     * course keeps its label and uploads for it keep validating.
     */
    private function adoptUnknownLanguage(string $code): void
    {
        if (DB::table('languages')->where('code', $code)->exists()) {
            return;
        }

        DB::table('languages')->insert([
            'code' => mb_substr($code, 0, 10),
            'name' => $code,
            'native_name' => $code,
            'is_active' => false,
            'sort_order' => 900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
