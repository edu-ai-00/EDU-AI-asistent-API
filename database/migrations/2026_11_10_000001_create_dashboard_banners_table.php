<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed banners on the app's dashboard (BR-SAWX2U).
 *
 * A banner is not a notification: it is a standing placement the app
 * re-evaluates on every dashboard build, so it appears and disappears with
 * the learner's state instead of being delivered once. That is why it lives
 * in its own table rather than as a channel on notification_types — a
 * delivered row cannot un-deliver itself when XP leaves the band.
 *
 * `min_xp` / `max_xp` travel to the client and are evaluated there: XP is
 * offline-first on the device and the server's copy lags behind a sync, so
 * only the app can make the band react the moment the number moves (the same
 * contract the hard-coded DashboardTiers had). Everything the device cannot
 * know for itself — course, class, school, role — is filtered server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_banners', function (Blueprint $table) {
            $table->id();
            // system.<slug> for seeded banners, null for ones an admin added.
            $table->string('key', 64)->nullable()->unique();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_system')->default(false);
            // Render order; also the tie-break when several bands overlap.
            $table->unsignedInteger('position')->default(0);

            // Content, as PromoCard takes it.
            $table->string('badge', 32)->nullable();
            $table->string('title');
            $table->text('body');
            $table->string('accent', 16)->default('primary');
            $table->string('illustration', 32)->nullable();
            // Deep link in the notification vocabulary (spec §8) plus the two
            // dashboard-only targets: /field-course/{slug}, /chat/{persona}.
            $table->string('link', 255)->nullable();

            // Targeting. Null means "no constraint"; the set ones combine with AND.
            $table->string('target_role', 16)->default('all');
            $table->unsignedInteger('min_xp')->nullable();
            $table->unsignedInteger('max_xp')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();

            // Plain ids: null for X-Admin-Key calls, and an editor leaving the
            // team must not take the banner's history with them.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_banners');
    }
};
