<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification catalog (NOTIF-1, NOTIF-5, NOTIF-9).
 *
 * One row per kind of notification the system can send. Rows are seeded by
 * NotificationCatalogSeeder (create-if-missing) and afterwards owned by the
 * admin: templates, limits and channels are edited here, the key never is.
 * There is no delete endpoint — user_notifications point at these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            // type_key, immutable after seed
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // provoz | navyk | pokrok | aktivace
            $table->string('category', 20);
            // student | teacher | all
            $table->string('target_role', 20);
            $table->boolean('is_active')->default(true);
            // Bypasses quiet hours, push opt-out and the frequency limit — never dedup.
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_system')->default(true);
            // Selectable in broadcasts.
            $table->boolean('manual_allowed')->default(false);
            // Allowed channels, non-empty subset of ["push","feed"].
            $table->json('channels');
            $table->boolean('respect_quiet_hours')->default(true);
            $table->unsignedInteger('frequency_limit')->nullable();
            $table->unsignedInteger('frequency_period_hours')->nullable();
            $table->unsignedInteger('dedup_window_hours')->default(24);
            $table->string('push_title');
            $table->text('push_body');
            $table->string('feed_title');
            $table->text('feed_body');
            // [{key, label, sample, fallback, required}]
            $table->json('variables');
            $table->string('deep_link')->nullable();
            // Domain action that counts as the notification's goal (NOTIF-8 F).
            $table->string('target_action', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
