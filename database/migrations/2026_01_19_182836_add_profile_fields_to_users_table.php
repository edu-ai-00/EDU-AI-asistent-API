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
        // Make password nullable using raw SQL (PostgreSQL syntax)
        // Use try-catch in case it's already nullable
        try {
            DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        } catch (\Exception $e) {
            // Column might already be nullable, ignore error
        }

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
