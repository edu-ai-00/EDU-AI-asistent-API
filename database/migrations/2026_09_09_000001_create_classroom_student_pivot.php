<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classroom membership moves from users.classroom_id (one class per student)
 * to a classroom_student pivot (BR-3V2P22): a student may sit in several
 * classes at once, and a student who already has an account can join a
 * class by typing the code the teacher handed out.
 *
 * Same shape as 2026_03_02_200002_migrate_teacher_id_to_pivot: copy, then
 * drop the old column so nothing can keep reading a stale single value —
 * teacher scoping on a half-migrated column would be a data-exposure bug,
 * so a loud SQL error beats a quiet wrong answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['classroom_id', 'user_id']);
            $table->index('user_id');
        });

        // Copy existing classroom_id values into the pivot table
        DB::table('users')
            ->whereNotNull('classroom_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('classroom_student')->insert([
                    'classroom_id' => $user->classroom_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classroom_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('classroom_id')->nullable()->after('device_id')
                ->constrained()->nullOnDelete();
        });

        // Copy the first membership back; the rest is lost, same as the
        // teacher_id rollback.
        DB::table('classroom_student')
            ->select('classroom_id', 'user_id')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->each(function ($rows, $userId) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['classroom_id' => $rows->first()->classroom_id]);
            });

        Schema::dropIfExists('classroom_student');
    }
};
