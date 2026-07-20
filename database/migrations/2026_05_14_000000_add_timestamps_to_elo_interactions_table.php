<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-interaction timing fields to elo_interactions.
 *
 * - opened_at:   when the student first revealed/opened the block
 * - confirmed_at: when the student submitted the answer
 * - duration_ms: pre-computed delta (confirmed_at - opened_at) in milliseconds
 *
 * For lesson interactions the client lifts these from
 * user_courses.progress_data.lessons[].block_timestamps. For quiz interactions
 * the quiz screen now tracks them directly. Historical rows are backfilled
 * by App\Http\Controllers\Api\EloBackfillController::runTimestampBackfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->timestampTz('opened_at')->nullable()->after('updated_indices');
            $table->timestampTz('confirmed_at')->nullable()->after('opened_at');
            $table->unsignedInteger('duration_ms')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->dropColumn(['opened_at', 'confirmed_at', 'duration_ms']);
        });
    }
};
