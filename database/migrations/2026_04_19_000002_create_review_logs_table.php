<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('practice_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('rating');
            $table->timestamp('shown_at');
            $table->timestamp('reviewed_at');
            $table->integer('response_time_sec');
            $table->integer('repetition_number');
            $table->double('stability_after');
            $table->double('difficulty_after');
            $table->timestamp('next_due_date');
            $table->integer('interval_days');
            $table->string('user_feedback')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'reviewed_at']);
            $table->index(['card_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_review_logs');
    }
};
