<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_avatar_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('avatar_item_id')->constrained('avatar_items')->cascadeOnDelete();

            // What the item actually cost at the time — 0 during the free
            // period. Kept per-row so a later price change does not rewrite
            // history.
            $table->unsignedInteger('price_paid')->default(0);

            $table->timestamp('acquired_at')->useCurrent();
            $table->timestamps();

            // Owning an item twice is meaningless; the purchase endpoint
            // relies on this to make repeat taps idempotent.
            $table->unique(['user_id', 'avatar_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_avatar_items');
    }
};
