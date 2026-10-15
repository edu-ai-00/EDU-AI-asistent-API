<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-KPXQA8 — assets used to belong to a course and nothing else, so the admin
 * Files section could only ever show course folders. A nullable `scope` gives
 * assets a course-less home (currently "news"); `course_id` becomes nullable so
 * those rows can sit outside the courses foreign key. The FK itself stays put —
 * it is not enforced for NULLs, so course deletion still cascades as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('scope')->nullable()->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assets ALTER COLUMN course_id DROP NOT NULL');
        } else {
            Schema::table('assets', function (Blueprint $table) {
                $table->string('course_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('assets')->whereNull('course_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assets ALTER COLUMN course_id SET NOT NULL');
        } else {
            Schema::table('assets', function (Blueprint $table) {
                $table->string('course_id')->nullable(false)->change();
            });
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['scope']);
            $table->dropColumn('scope');
        });
    }
};
