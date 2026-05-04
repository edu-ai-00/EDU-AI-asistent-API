<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fsrs_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->double('desired_retention')->default(0.9);
            $table->integer('maximum_interval')->default(90);
            $table->boolean('enable_fuzz')->default(true);
            $table->boolean('enable_short_term')->default(true);
            $table->json('learning_steps')->default('["1m","10m"]');
            $table->json('relearning_steps')->default('["10m"]');
            $table->json('fsrs_weights')->nullable();
            $table->integer('profile_version')->default(1);
            $table->integer('daily_new_limit')->default(10);
            $table->integer('daily_review_limit')->default(50);
            $table->integer('session_expiration_sec')->default(7200);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fsrs_profiles');
    }
};
