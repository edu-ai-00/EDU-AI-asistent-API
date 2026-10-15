<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The EDUX coin wallet (BR-HK9828).
     *
     * Two counters rather than one: `coins_earned` is the lifetime total the
     * Achievements screen shows and only ever grows, while `coins_balance` is
     * what the shop will charge against once items stop being free. Keeping
     * them apart means spending a coin cannot quietly shrink the number the
     * student is shown as having earned.
     */
    public function up(): void
    {
        Schema::table('user_stats', function (Blueprint $table) {
            $table->unsignedInteger('coins_earned')->default(0)->after('daily_xp_amount');
            $table->unsignedInteger('coins_balance')->default(0)->after('coins_earned');
        });
    }

    public function down(): void
    {
        Schema::table('user_stats', function (Blueprint $table) {
            $table->dropColumn(['coins_earned', 'coins_balance']);
        });
    }
};
