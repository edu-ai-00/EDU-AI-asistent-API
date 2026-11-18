<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual notifications sent by an admin to schools / classes (NOTIF-10).
 * A broadcast is delivered through the same pipeline as everything else: its
 * send emits `broadcast.sent` for the recipient snapshot, and the system rule
 * of its type turns that into notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            // A manual_allowed notification type.
            $table->string('type_key', 64);
            $table->json('channels');
            // {school_ids, classroom_ids, roles}
            $table->json('targeting');
            // Names resolved at send, so the history still reads after a
            // school or class is renamed or deleted.
            $table->json('targeting_snapshot')->nullable();
            $table->timestamp('deadline_at')->nullable();
            // draft | sending | sent | failed | archived
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('deadline_reminded_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('deadline_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');
    }
};
