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
        Schema::create('mini_game_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mini_game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('played_at');
            $table->unsignedInteger('score')->nullable();
            $table->timestamps();

            // Daily quota and cooldown are both derived from this one lookup:
            // "this user's plays of this game, most recent first".
            $table->index(['user_id', 'mini_game_id', 'played_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mini_game_plays');
    }
};
