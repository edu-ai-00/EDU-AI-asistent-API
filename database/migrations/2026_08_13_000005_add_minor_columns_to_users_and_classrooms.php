<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_minor')->default(false)->after('is_guest');
            // Minor login codes live here as an HMAC — never in login_code.
            $table->string('login_code_hash', 64)->nullable()->unique()->after('login_code');
            // restrictOnDelete: consents.guardian_id cascades on delete, so an
            // unguarded guardian delete would silently wipe the consent evidence
            // while leaving this minor's account orphaned. Force callers through
            // the ordered eraser (Task 10), which removes children first.
            $table->foreignId('guardian_id')->nullable()->after('classroom_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('classrooms', function (Blueprint $table) {
            // school = created by a teacher in admin; self_service = guardian-owned
            $table->string('type', 20)->default('school')->after('name');
            // restrictOnDelete: same reasoning as users.guardian_id above.
            $table->foreignId('guardian_id')->nullable()->after('type')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_id');
            // SQLite's native ALTER TABLE DROP COLUMN refuses to drop a column that
            // still backs a unique index; drop the index explicitly first. This is a
            // no-op-equivalent, safe addition on MySQL (DROP COLUMN there already
            // cascades to the index, but dropping it explicitly first is also fine).
            $table->dropUnique(['login_code_hash']);
            $table->dropColumn(['is_minor', 'login_code_hash']);
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_id');
            $table->dropColumn('type');
        });
    }
};
