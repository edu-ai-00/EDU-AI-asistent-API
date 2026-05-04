<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copy existing teacher_id values into the pivot table
        DB::table('classrooms')
            ->whereNotNull('teacher_id')
            ->orderBy('id')
            ->each(function ($classroom) {
                DB::table('classroom_teacher')->insert([
                    'classroom_id' => $classroom->id,
                    'teacher_id' => $classroom->teacher_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // Drop the old teacher_id column
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
        });
    }

    public function down(): void
    {
        // Re-add teacher_id column
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
        });

        // Copy first teacher back from pivot
        DB::table('classroom_teacher')
            ->select('classroom_id', 'teacher_id')
            ->orderBy('id')
            ->get()
            ->groupBy('classroom_id')
            ->each(function ($teachers, $classroomId) {
                DB::table('classrooms')
                    ->where('id', $classroomId)
                    ->update(['teacher_id' => $teachers->first()->teacher_id]);
            });
    }
};
