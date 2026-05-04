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
        Schema::create('elo_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('block_id');
            $table->string('course_id');
            $table->float('score');                     // 0.0, 0.5, 0.75, or 1.0
            $table->json('profil_elo_snapshot')->nullable();  // snapshot at time of answer
            $table->json('elo_vector_snapshot')->nullable();  // block's elo_vector at time
            $table->timestamps();
            $table->index(['user_id', 'block_id']);
            $table->index(['block_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elo_interactions');
    }
};
