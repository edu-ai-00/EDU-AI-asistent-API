<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Make password nullable (social/guest/verified-email users have none).
        // Uses a DB-agnostic column change (doctrine/dbal) so it applies on
        // SQLite too — the previous raw PostgreSQL ALTER silently no-op'd
        // elsewhere, leaving the column NOT NULL in the test database.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        // Add profile fields if they don't exist
        if (!Schema::hasColumn('users', 'avatar_index')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('avatar_index')->nullable();
            });
        }

        if (!Schema::hasColumn('users', 'selected_subjects')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('selected_subjects')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_index')) {
                $table->dropColumn('avatar_index');
            }
            if (Schema::hasColumn('users', 'selected_subjects')) {
                $table->dropColumn('selected_subjects');
            }
        });

        DB::statement("ALTER TABLE users ALTER COLUMN password SET NOT NULL");
    }
};
