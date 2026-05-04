<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('course_id');
            $table->string('lesson_id');
            $table->string('block_id');
            $table->string('source_type');
            $table->tinyInteger('state')->default(0);
            $table->timestamp('due_date')->useCurrent();
            $table->double('stability')->default(0.0);
            $table->double('difficulty')->default(0.0);
            $table->integer('reps')->default(0);
            $table->integer('lapses')->default(0);
            $table->integer('scheduled_days')->default(0);
            $table->integer('elapsed_days')->default(0);
            $table->timestamp('last_review')->nullable();
            $table->double('weight')->default(5.0);
            $table->integer('avg_time_sec')->default(20);
            $table->string('skip_condition')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'block_id']);
            $table->index(['user_id', 'is_active', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_cards');
    }
};
