<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coins collected during a run (BR-HK9828).
     *
     * Nullable and beside `score` for the same reason: the row is created when
     * the run *starts*, and both numbers are only known once it ends. Runs
     * recorded before this column existed stay null — they predate the wallet
     * and were never credited.
     */
    public function up(): void
    {
        Schema::table('mini_game_plays', function (Blueprint $table) {
            $table->unsignedInteger('coins')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('mini_game_plays', function (Blueprint $table) {
            $table->dropColumn('coins');
        });
    }
};
