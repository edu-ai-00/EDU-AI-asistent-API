<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule bookkeeping per user — today only the weekly XP goal baseline: the XP
 * total at the start of the ISO week, so "this week's XP" can be told apart
 * from the lifetime total user_stats keeps (goal.completed, period weekly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_user_states', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            // ISO week, e.g. 2026-W41
            $table->string('week_key', 8)->nullable();
            $table->integer('week_start_xp')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_user_states');
    }
};
