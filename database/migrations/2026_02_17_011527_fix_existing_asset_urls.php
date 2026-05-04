<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $baseUrl = rtrim(config('filesystems.disks.s3.url'), '/');

        if (empty($baseUrl)) {
            return;
        }

        DB::table('assets')
            ->where('url', 'not like', 'http%')
            ->update([
                'url' => DB::raw("'{$baseUrl}' || url"),
            ]);
    }

    public function down(): void
    {
        // Not reversible — URLs would need manual inspection
    }
};
