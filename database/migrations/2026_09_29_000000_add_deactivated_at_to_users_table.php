<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an admin switched the account off (BR-Q3ZJ6Q).
     *
     * Until now the only way to take access away was to demote a team member
     * to student — a student could not be locked out at all. Null means the
     * account is active; a timestamp means no login path may issue it a
     * token (User::createToken) and every token it already held was revoked
     * at that moment. Reactivating sets it back to null. The account and all
     * of its data stay put either way — this is not a deletion.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('merged_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
