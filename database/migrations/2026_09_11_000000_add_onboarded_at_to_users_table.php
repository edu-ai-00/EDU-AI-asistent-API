<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the account finished the sign-up onboarding (BR-YXVMKY).
     *
     * The onboarding — nickname, colour profile, avatar vibe — is a one-time
     * step of joining, and until now the API had nothing that said so. Each
     * login path guessed instead: SSO asked "does this account have
     * selected_subjects" (nothing ever writes that column for an SSO account,
     * so every login re-onboarded), and a student login code answered "no"
     * outright (so a student never onboarded at all). One timestamp, written
     * once when the student finishes, is what makes "only on the very first
     * entry" a fact of the account rather than of the device it was first
     * opened on — BR-D27TFA removed the device-local flag this replaces.
     *
     * Every account that exists at deploy time counts as onboarded: they are
     * the existing users the report says must not be asked again. Guests are
     * excluded — a guest has not joined yet, and the onboarding is what runs
     * when they do.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'onboarded_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('onboarded_at')->nullable()->after('selected_subjects');
            });
        }

        DB::table('users')
            ->whereNull('onboarded_at')
            ->where(function ($query) {
                $query->where('is_guest', false)->orWhereNull('is_guest');
            })
            ->update(['onboarded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
