<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classrooms get an optional school.
     *
     * Nullable and unbackfilled on purpose: every classroom that exists today
     * stays school-less, so the school-mate visibility added in
     * AdminClassroomController::index() cannot widen anyone's view until an
     * admin actually files a class under a school. Sorting the existing rows
     * into schools is an admin action, not a deploy-time guess.
     */
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('type')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
        });
    }
};
