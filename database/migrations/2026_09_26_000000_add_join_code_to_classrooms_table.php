<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classrooms get an optional class code (BR-TT6TD7): four characters the
     * teacher picks, which any signed-in student can type (or open as
     * /join/XXXX) to walk into the class without being put on the roster by
     * hand.
     *
     * Nullable and unbackfilled: a class is only self-service once a teacher
     * gives it a code. Unique across all classes, because the code is the
     * only thing the student types — there is no school to narrow it by.
     * Several NULLs are fine under a unique index on every engine we run.
     */
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->string('join_code', 4)->nullable()->unique()->after('school_id');
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn('join_code');
        });
    }
};
