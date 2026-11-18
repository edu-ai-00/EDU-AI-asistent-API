<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of every per-recipient decision (deliver / defer /
 * suppress / reject) and every state transition of a notification (NOTIF-8).
 *
 * user_id has no foreign key on purpose: a decision about a recipient that
 * does not exist (user_not_found) must still be loggable, and a rejected
 * send has no notification row to hang off. StudentDataEraser deletes a
 * child's rows explicitly for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->nullable()
                ->constrained('user_notifications')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type_key', 64)->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->unsignedBigInteger('broadcast_id')->nullable();
            // decision.deliver | decision.defer | decision.suppress |
            // decision.reject | created | queued | sent | delivered | viewed |
            // clicked | app_opened | failed | target_action
            $table->string('event', 32);
            $table->string('reason', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('notification_id');
            $table->index('broadcast_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
