<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formative feedback, audit trail and photo answers (BR-P745Q8).
 *
 * BR-FFVVYR stored a score and one sentence, which was enough while the feature
 * was a grader. Three things the new brief requires do not fit that shape:
 *
 *  - Feedback now has three named parts. Flattening them into one column would
 *    make the teacher view unable to show, say, only what a cohort keeps
 *    getting told to improve.
 *  - "Když učitel očekávanou odpověď nebo kritéria později změní, starší pokusy
 *    musí zůstat interpretovatelné." Nothing here recorded what an attempt was
 *    judged against, so editing a question silently rewrote history.
 *  - A failed evaluation is an outcome worth keeping. Previously the client
 *    fell back to string matching and the failure vanished.
 *
 * All columns are additive and nullable, so rows written by BR-FFVVYR clients
 * stay valid and keep rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_answer_evaluations', function (Blueprint $table) {
            // {strengths, improvements, next_step, hint_question}
            $table->json('feedback_parts')->nullable();

            // [{id, name, score}] — only when the question defines criteria.
            $table->json('criteria_scores')->nullable();

            // What this attempt was judged against, snapshotted at evaluation
            // time: question, expected answer, criteria, model, prompt version.
            $table->json('grading_basis')->nullable();

            // evaluated — feedback was produced and is shown to the student.
            // failed    — AI was unreachable or unusable; the student saw a
            //             retry, and this row is the log entry for it.
            $table->string('attempt_status', 20)->default('evaluated');

            // 1-based, per (user, course, block, step). A retry is a new row,
            // never an overwrite of the attempt that failed.
            $table->unsignedSmallInteger('attempt_number')->default(1);

            // R2 object key of a photographed answer. Private — served only
            // through AnswerPhotoController, never as a public URL.
            $table->string('photo_path')->nullable();

            $table->string('prompt_version', 40)->nullable();
        });

        Schema::table('open_answer_evaluations', function (Blueprint $table) {
            // The teacher view lists attempts for one question in order, and
            // the client asks "what is my latest attempt here?" on resume.
            $table->index(
                ['user_id', 'course_id', 'block_id', 'step_id', 'attempt_number'],
                'open_answers_attempt_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('open_answer_evaluations', function (Blueprint $table) {
            $table->dropIndex('open_answers_attempt_idx');
            $table->dropColumn([
                'feedback_parts',
                'criteria_scores',
                'grading_basis',
                'attempt_status',
                'attempt_number',
                'photo_path',
                'prompt_version',
            ]);
        });
    }
};
