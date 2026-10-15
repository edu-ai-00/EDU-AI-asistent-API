<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side task difficulty calibration (BR-697NCA, design BR-QF2N45).
 *
 * The server becomes the only writer of a block's difficulty D and its update
 * count m (item_pocet). Until now D lived nowhere: the app reset it to the
 * course's elo_vector on every answer, so the task K-factor 1/(1 + 0.1·m)
 * never had an effect.
 *
 * block_stats.elo_vector — calibrated D, one global value per block_id and
 * dimension; NULL in a dimension no answer has calibrated yet, where the
 * course's own elo_vector still applies.
 *
 * elo_interactions gains what the server needs to apply and replay the task
 * half of each answer:
 * - client_uuid: the app's idempotency key; a retried send returns the stored
 *   row instead of moving D twice. NULL for rows from older builds.
 * - profil_elo_before: the student's L before the answer (the existing
 *   profil_elo_snapshot is L after). NULL for older builds — those are
 *   logged, not applied live, and folded in by elo:recalibrate.
 * - elo_vector_used: the D the app's student update ran on.
 * - task_applied / task_skip_reason: whether this row moved D, and if not, why.
 *
 * Existing item_pocet values came from backfill runs that never moved D, so
 * they are left in place for elo:recalibrate to rebuild, not reset here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_stats', function (Blueprint $table) {
            $table->json('elo_vector')->nullable()->after('item_pocet');
        });

        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->after('user_id');
            $table->json('profil_elo_before')->nullable()->after('score');
            $table->json('elo_vector_used')->nullable()->after('profil_elo_snapshot');
            $table->boolean('task_applied')->default(false)->after('elo_vector_snapshot');
            $table->string('task_skip_reason', 32)->nullable()->after('task_applied');
            $table->unique(['user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_uuid']);
            $table->dropColumn([
                'client_uuid',
                'profil_elo_before',
                'elo_vector_used',
                'task_applied',
                'task_skip_reason',
            ]);
        });

        Schema::table('block_stats', function (Blueprint $table) {
            $table->dropColumn('elo_vector');
        });
    }
};
