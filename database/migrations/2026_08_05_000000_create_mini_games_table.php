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
        Schema::create('mini_games', function (Blueprint $table) {
            $table->id();
            // Stable identifier the client uses to resolve the bundled game
            // engine. The engine ships with the app build; only its metadata
            // and play entitlement live here.
            $table->string('code')->unique();
            $table->string('title');
            $table->string('perex');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            // Hardware the game needs, e.g. "gyroscope". Rendered as a tag.
            $table->string('sensor')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            // Play entitlement. All three are evaluated server-side.
            $table->unsignedSmallInteger('min_level')->default(1);
            // null = unlimited plays per day.
            $table->unsignedSmallInteger('daily_attempts')->nullable();
            // null or 0 = no waiting period between plays.
            $table->unsignedSmallInteger('cooldown_hours')->nullable();

            // Free-form per-game configuration, developed separately.
            $table->json('setup')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Listing is always "active games, in display order".
            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mini_games');
    }
};
