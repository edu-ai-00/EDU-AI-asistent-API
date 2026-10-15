<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized copy of the course JSON's `open_all_lessons` flag, so the
     * listing endpoint can serve it without decoding the full course data
     * (BR-K47WYE).
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('open_all_lessons')->default(false)->after('quiz_evaluate');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('open_all_lessons');
        });
    }
};
