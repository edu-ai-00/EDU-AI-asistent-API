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
        // Make email nullable (PostgreSQL allows multiple NULLs in unique columns)
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'mysql' || $driver === 'mariadb') {
            try {
                DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
            } catch (\Exception) {
                // Column might already be nullable
            }
        } else {
            // SQLite (used in tests) — use Laravel's schema builder for nullable change.
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('email');
            $table->string('device_id')->nullable()->after('is_guest');
            $table->index('device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn(['is_guest', 'device_id']);
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};
