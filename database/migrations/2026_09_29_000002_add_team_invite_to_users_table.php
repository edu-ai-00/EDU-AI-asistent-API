<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team invite lifecycle: invited → active, with an expiry (BR-XW6CWV).
 *
 * The state is derived from these timestamps rather than stored as its own
 * column (see User::teamStatus()), so every admin and teacher that existed
 * before this migration has both null and reads as `active` with no backfill.
 * Deactivation is users.deactivated_at from BR-Q3ZJ6Q.
 *
 *  - invited_at        when the last invite (or resend) went out
 *  - invite_expires_at set while an invite is pending; cleared by the first
 *                      admin sign-in, which is what accepting it means
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('invite_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'invite_expires_at']);
        });
    }
};
