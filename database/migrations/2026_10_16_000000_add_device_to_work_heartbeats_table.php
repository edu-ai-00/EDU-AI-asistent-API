<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device a heartbeat was recorded on (BR-Y2TJ74), so the admin work-time
 * view can show web/Android/iOS and phone/tablet/PC next to each session.
 *
 * Both columns are nullable: rows synced before this existed, and rows from
 * older app builds, simply have no device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_heartbeats', function (Blueprint $table) {
            // web | android | ios | macos | windows | linux
            $table->string('platform', 16)->nullable()->after('lesson_id');
            // mobile | tablet | desktop
            $table->string('device_type', 16)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('work_heartbeats', function (Blueprint $table) {
            $table->dropColumn(['platform', 'device_type']);
        });
    }
};
