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
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->json('updated_indices')->nullable()->after('elo_vector_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->dropColumn('updated_indices');
        });
    }
};
