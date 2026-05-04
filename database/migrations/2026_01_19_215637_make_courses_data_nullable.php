<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For PostgreSQL, use raw SQL to alter column
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE courses ALTER COLUMN data DROP NOT NULL');
        } else {
            Schema::table('courses', function (Blueprint $table) {
                $table->json('data')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE courses ALTER COLUMN data SET NOT NULL');
        } else {
            Schema::table('courses', function (Blueprint $table) {
                $table->json('data')->nullable(false)->change();
            });
        }
    }
};
