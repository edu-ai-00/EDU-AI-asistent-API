<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds soft-merge tracking columns to the users table. When two profiles
     * are merged the source row is preserved (audit/login-redirect) and
     * pointed at the target via merged_into_user_id.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('merged_into_user_id')
                ->nullable()
                ->after('classroom_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('merged_at')->nullable()->after('merged_into_user_id');

            $table->index('merged_into_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['merged_into_user_id']);
            $table->dropIndex(['merged_into_user_id']);
            $table->dropColumn(['merged_into_user_id', 'merged_at']);
        });
    }
};
