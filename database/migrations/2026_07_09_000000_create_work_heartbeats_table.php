<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw activity heartbeats used to measure active working time (BR-9SAH2R).
 *
 * The Flutter client emits one row per ~60s while a learning screen is
 * foreground and genuine activity was seen. Rows sync in batches and are
 * idempotent on (user_id, client_uuid) so re-syncs do not duplicate. Sessions
 * and aggregates are derived on the server by App\Support\WorkSessionizer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid');
            $table->string('course_id');
            $table->string('lesson_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'course_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_heartbeats');
    }
};
