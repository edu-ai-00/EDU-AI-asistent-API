<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recipient per notification — and the in-app feed (NOTIF-1, 4, 8).
 *
 * Not called `notifications`: User uses Laravel's Notifiable trait, which
 * owns that table name.
 *
 * Texts are rendered once at send and frozen here, so a later template edit
 * never rewrites what a user already received (NOTIF-5 F). rule_id and
 * broadcast_id are plain ids, not foreign keys: a deleted custom rule must
 * not take the delivery history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            // Public notification_id (ULID) — deep links, push data, app events.
            $table->char('uuid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_type_id')->constrained('notification_types');
            $table->string('type_key', 64);
            $table->string('category', 20);
            // rule | broadcast | test
            $table->string('source', 20);
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->unsignedBigInteger('broadcast_id')->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('dedup_key', 191);
            // Effective channels for this recipient.
            $table->json('channels');
            $table->boolean('in_feed')->default(true);
            $table->string('title');
            $table->text('body');
            $table->string('push_title')->nullable();
            $table->text('push_body')->nullable();
            $table->string('deep_link')->nullable();
            // Variables used to render the texts.
            $table->json('payload');
            // Furthest state reached: created → queued → sent → delivered →
            // viewed → clicked → app_opened, plus failed.
            $table->string('status', 32)->default('created');
            // deferred | queued | sent | delivered | failed | skipped
            $table->string('push_status', 20)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('deferred_until')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('app_opened_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('target_action_at')->nullable();
            // Feed read state.
            $table->timestamp('read_at')->nullable();
            $table->string('target_action', 64)->nullable();
            $table->timestamps();

            // Dedup window lookup (NOTIF-1 E).
            $table->index(['user_id', 'dedup_key', 'created_at']);
            // The feed and its unread badge.
            $table->index(['user_id', 'in_feed', 'created_at']);
            // Frequency limit per type, daily cap per category (NOTIF-6 B).
            $table->index(['user_id', 'notification_type_id', 'created_at']);
            $table->index(['user_id', 'category', 'created_at']);
            // notifications:retry-stuck — deferred pushes that are due.
            $table->index(['push_status', 'deferred_until']);
            $table->index('broadcast_id');
            $table->index('created_at');
            // Admin: last_sent_at per type, history filtered by type.
            $table->index(['notification_type_id', 'created_at']);
            $table->index(['type_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
