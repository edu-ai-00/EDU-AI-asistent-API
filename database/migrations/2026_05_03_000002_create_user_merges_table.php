<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Audit table for User Merge operations. Source/target user FKs use
     * RESTRICT (no cascade) so the audit row survives if a user row is ever
     * purged — the audit history must outlive the underlying users.
     */
    public function up(): void
    {
        Schema::create('user_merges', function (Blueprint $table) {
            $table->id();

            // SPEC §4.2: source/target FKs do NOT cascade-delete — audit row
            // is the source of truth even if the underlying user vanishes.
            $table->unsignedBigInteger('source_user_id');
            $table->unsignedBigInteger('target_user_id');
            $table->unsignedBigInteger('performed_by');

            $table->json('source_snapshot');
            $table->json('target_before');
            $table->json('strategy_log');

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('source_user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();
            $table->foreign('target_user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();
            $table->foreign('performed_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index('source_user_id');
            $table->index('target_user_id');
            $table->index('performed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_merges');
    }
};
