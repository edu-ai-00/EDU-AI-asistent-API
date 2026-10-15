<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which ELO configuration produced an interaction's snapshots (BR-EBNMBF).
     *
     * Pilot 2 data has to be told apart by engine configuration, and the
     * engine carries a label for exactly that (EloEngine::CONFIG_VERSION,
     * kPilot2EloConfigVersion in the app) — but nothing stored it. The app
     * sends the version it computed with; the admin backfill stamps the PHP
     * engine's.
     *
     * Existing rows stay NULL: nothing records which engine an app build was
     * running when it wrote them, so any value filled in now would be a guess.
     */
    public function up(): void
    {
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->string('elo_config_version', 64)->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('elo_interactions', function (Blueprint $table) {
            $table->dropColumn('elo_config_version');
        });
    }
};
