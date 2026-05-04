<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_event_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('student_id');
            $table->string('course_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('block_id')->nullable();
            $table->json('dimension_indices')->nullable();
            $table->json('delta_values')->nullable();
            $table->float('score');
            $table->bigInteger('ts_bubble_open')->nullable();
            $table->bigInteger('ts_answer_click')->nullable();
            $table->bigInteger('ts_answer_submit')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->integer('attempt_count')->default(1);
            $table->boolean('help_used')->default(false);
            $table->integer('time_on_task_ms')->nullable();
            $table->json('extra_events_json')->nullable();
            $table->timestamps();

            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['student_id', 'course_id']);
            $table->index('block_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_event_logs');
    }
};
