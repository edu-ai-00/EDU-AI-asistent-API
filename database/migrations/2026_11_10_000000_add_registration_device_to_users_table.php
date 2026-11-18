<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device of an account's first sign-in to the app (BR-CMA88Y), so the
 * admin student detail can show the device a pupil registered on even when
 * they have done nothing since — work sessions (BR-Y2TJ74) only exist once
 * the pupil works in a lesson or quiz.
 *
 * All nullable: accounts from before this have no recorded device, and
 * neither do sign-ins from older app builds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // web | android | ios | macos | windows | linux
            $table->string('registration_platform', 16)->nullable();
            // mobile | tablet | desktop
            $table->string('registration_device_type', 16)->nullable();
            $table->timestamp('registration_device_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['registration_platform', 'registration_device_type', 'registration_device_at']);
        });
    }
};
