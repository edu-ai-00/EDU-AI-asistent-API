<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds download version tracking to user_courses for R2 sync.
     */
    public function up(): void
    {
        Schema::table('user_courses', function (Blueprint $table) {
            $table->integer('downloaded_version')->nullable()->after('time_spent_seconds');
            $table->timestamp('downloaded_at')->nullable()->after('downloaded_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_courses', function (Blueprint $table) {
            $table->dropColumn(['downloaded_version', 'downloaded_at']);
        });
    }
};
