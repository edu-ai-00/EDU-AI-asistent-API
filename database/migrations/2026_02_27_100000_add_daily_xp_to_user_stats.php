<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_stats', function (Blueprint $table) {
            $table->date('daily_xp_date')->nullable()->after('last_streak_date');
            $table->integer('daily_xp_amount')->default(0)->after('daily_xp_date');
        });
    }

    public function down(): void
    {
        Schema::table('user_stats', function (Blueprint $table) {
            $table->dropColumn(['daily_xp_date', 'daily_xp_amount']);
        });
    }
};
