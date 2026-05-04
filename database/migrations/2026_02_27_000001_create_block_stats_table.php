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
        Schema::create('block_stats', function (Blueprint $table) {
            $table->id();
            $table->string('block_id')->unique();       // matches block_id in course JSON
            $table->json('item_pocet')->nullable();     // 35-element array of ints (global solve count)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('block_stats');
    }
};
