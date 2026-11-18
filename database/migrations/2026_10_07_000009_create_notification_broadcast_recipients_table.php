<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recipient snapshot of a broadcast, taken at send (NOTIF-10 F).
 *
 * user_id has no foreign key: the snapshot is a record of who the broadcast
 * was addressed to and must survive an account being deleted. A child's rows
 * are still removed on erasure (StudentDataEraser), and a merge moves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('notification_broadcasts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('role', 20);
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('classroom_id')->nullable();

            $table->unique(['broadcast_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcast_recipients');
    }
};
