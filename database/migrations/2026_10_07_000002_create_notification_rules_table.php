<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules turning domain events into notifications (NOTIF-7, NOTIF-9).
 *
 * `definition` is the JSON the admin edits, stored verbatim. event_key and
 * notification_type_id are copies of definition.when.event / then.type kept
 * so the rule engine can find the rules for an event with an index instead
 * of scanning JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            // system.<type_key> for seeded rules, null for custom ones.
            $table->string('key', 64)->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(false);
            $table->string('event_key', 64)->index();
            $table->foreignId('notification_type_id')->constrained('notification_types');
            $table->json('definition');
            // Plain ids: null for X-Admin-Key calls, and an editor leaving the
            // team must not take the rule's history with them.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
