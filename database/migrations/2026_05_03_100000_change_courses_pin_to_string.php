<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert courses.pin from unsignedInteger to alphanumeric string(6).
     * Existing numeric pins are preserved by casting to string and uppercasing.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('pin', 6)->nullable()->change();
        });

        // Normalize existing values (uppercase) so lookups match.
        DB::table('courses')
            ->whereNotNull('pin')
            ->update(['pin' => DB::raw('UPPER(pin)')]);
    }

    /**
     * Reverse the migrations.
     * Non-numeric pins will be NULL'd before reverting to integer column.
     */
    public function down(): void
    {
        DB::table('courses')
            ->whereNotNull('pin')
            ->where('pin', 'NOT REGEXP', '^[0-9]+$')
            ->update(['pin' => null]);

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedInteger('pin')->nullable()->change();
        });
    }
};
