<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->integer('progress_percent')->default(0);
            $table->enum('status', ['downloaded', 'in_progress', 'completed'])->default('downloaded');
            $table->integer('completed_lessons')->default(0);
            $table->integer('total_lessons')->nullable();
            $table->integer('current_lesson_index')->default(0);
            $table->integer('time_spent_seconds')->default(0);
            $table->json('progress_data')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Unique constraint: a user can only have one entry per course.
            $table->unique(['user_id', 'course_id']);

            // Index for efficient queries by user and status.
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_courses');
    }
};
