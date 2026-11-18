<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification settings (NOTIF-3, NOTIF-6). The row is created
 * lazily on the first PUT; a user without one gets the config defaults.
 * Null in any column means "use the default", not "off" — except
 * reminder_time, where null is the opt-in learning reminder switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            // Optional (non-mandatory) push only — mandatory types ignore it.
            $table->boolean('push_enabled')->default(true);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->time('reminder_time')->nullable();
            // IANA name sent by the app.
            $table->string('timezone', 64)->nullable();
            $table->timestamps();

            $table->index('reminder_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
