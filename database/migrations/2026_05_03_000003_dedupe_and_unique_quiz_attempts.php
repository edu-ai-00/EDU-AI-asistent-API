<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: collapse existing duplicates. A duplicate is any row that shares
        // (user_id, course_id, started_at) with another. Keep the lowest id per group.
        $groups = DB::table('quiz_attempts')
            ->select('user_id', 'course_id', 'started_at', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('started_at')
            ->groupBy('user_id', 'course_id', 'started_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            DB::table('quiz_attempts')
                ->where('user_id', $group->user_id)
                ->where('course_id', $group->course_id)
                ->where('started_at', $group->started_at)
                ->where('id', '!=', $group->keep_id)
                ->delete();
        }

        // Step 2: enforce idempotency for future writes.
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'course_id', 'started_at'],
                'quiz_attempts_user_course_started_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropUnique('quiz_attempts_user_course_started_unique');
        });
    }
};
