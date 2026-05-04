<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add shared-device session tracking to Sanctum tokens.
     *
     * shared_device — true when issued for a shared/classroom device. Drives
     * 8h session hard cap and shorter rotation TTL on the client.
     *
     * session_started_at — anchor for the 8h hard cap. Preserved across token
     * rotations so the cap is measured from initial login, not last rotate.
     *
     * Existing tokens are marked persistent (shared_device=false) so logged-in
     * users do not get rolled out by deploy.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->boolean('shared_device')->default(false)->after('abilities');
            $table->timestamp('session_started_at')->nullable()->after('shared_device');
        });

        DB::table('personal_access_tokens')->update([
            'shared_device' => false,
            'session_started_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['shared_device', 'session_started_at']);
        });
    }
};
