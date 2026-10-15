<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only trail of who deactivated or reactivated an account, and
     * when (BR-Q3ZJ6Q).
     *
     * Both user columns are nullOnDelete: users are hard-deleted (minor
     * erasure, StudentDataEraser), and that must not be blocked by — nor take
     * with it — the record that an admin once switched the account off.
     */
    public function up(): void
    {
        Schema::create('user_account_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Null when the call came in on X-Admin-Key alone (no operator).
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            // deactivated | reactivated
            $table->string('event', 40);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_events');
    }
};
