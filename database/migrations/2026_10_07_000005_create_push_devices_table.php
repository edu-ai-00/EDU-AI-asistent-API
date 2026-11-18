<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FCM registration tokens (NOTIF-3). FCM fronts APNs on iOS, so one token
 * column serves every platform.
 *
 * A device belongs to the Sanctum session that registered it, not just to the
 * user: logging out, the token expiring or `sanctum:prune-expired` deletes the
 * session and the cascade takes the device with it. On a shared school tablet
 * that is what stops the next pupil's phone-free session from receiving the
 * previous one's pushes. Token rotation (/api/auth/refresh) re-points the
 * device at the new session instead (SessionTokens::reissue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One device = one user: registering a known token moves it.
            $table->string('token', 512)->unique();
            // android | ios | macos
            $table->string('platform', 16);
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Set when FCM says the token is dead (UNREGISTERED and friends).
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
