<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Graded free-text answers from lessons and exercises (BR-FFVVYR).
 *
 * Quiz answers already persist inside quiz_attempts.answers, but open questions
 * answered outside quiz mode had nowhere to live — user_progress only keeps
 * aggregate progress. Without this table a teacher cannot see what a student
 * actually wrote, which is the whole point of AI-graded open questions.
 *
 * Idempotent on (user_id, client_uuid): the Flutter client is offline-first and
 * may replay a submission, so it stamps each answer with a UUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_answer_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid');

            $table->string('course_id');
            $table->string('lesson_id')->nullable();
            $table->string('block_id');
            $table->string('step_id')->nullable();

            $table->text('answer_text');
            $table->boolean('is_correct')->default(false);

            // Null when the answer fell back to local string comparison
            // (offline, timeout, or AI disabled for the question).
            $table->decimal('ai_score', 4, 3)->nullable();
            $table->text('ai_feedback')->nullable();
            $table->boolean('evaluated_by_ai')->default(false);
            $table->string('ai_model')->nullable();

            // Reserved for teacher override — shipped unused so enabling the
            // feature later needs no migration.
            $table->decimal('teacher_override_score', 4, 3)->nullable();

            $table->timestampTz('answered_at');
            $table->timestamps();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'course_id']);
            $table->index(['course_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_answer_evaluations');
    }
};
