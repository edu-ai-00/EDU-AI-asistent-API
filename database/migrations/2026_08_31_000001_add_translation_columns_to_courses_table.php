<?php

use App\Services\CourseStructureFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-AYACEK — group a course's language variants and record their structure.
 *
 * `translation_key` is the shared identity of a translation group; each variant
 * keeps its own `course_id`, so every existing unique constraint — course_id,
 * pin — stays intact, and so do progress, practice cards and quiz attempts,
 * which are all keyed off course_id.
 *
 * `structure_hash` is the fingerprint uploads compare against, so a translation
 * that quietly changes the teaching logic can be refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('translation_key')->nullable()->after('course_id');
            $table->string('structure_hash', 64)->nullable()->after('version');

            $table->index('translation_key');
        });

        // Every existing course becomes its own single-variant group, so nothing
        // starts out with a sibling and no existing upload flow changes shape.
        DB::table('courses')->update(['translation_key' => DB::raw('course_id')]);

        $this->backfillStructureHashes();
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['translation_key']);
            $table->dropColumn(['translation_key', 'structure_hash']);
        });
    }

    /**
     * Fingerprint the JSON already stored in `courses.data`. Courses whose data
     * column is empty (R2-only legacy rows) keep a null hash — the upload check
     * skips a variant it cannot fingerprint rather than blocking on it.
     */
    private function backfillStructureHashes(): void
    {
        DB::table('courses')->select('id', 'data')->orderBy('id')
            ->chunk(200, function ($courses) {
                foreach ($courses as $course) {
                    $data = json_decode((string) $course->data, true);

                    if (!is_array($data) || $data === []) {
                        continue;
                    }

                    DB::table('courses')->where('id', $course->id)->update([
                        'structure_hash' => CourseStructureFingerprint::compute($data),
                    ]);
                }
            });
    }
};
