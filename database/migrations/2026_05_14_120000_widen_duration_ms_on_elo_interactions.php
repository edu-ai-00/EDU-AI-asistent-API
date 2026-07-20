<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `duration_ms` was originally `unsignedInteger` which Laravel maps to
 * Postgres `integer` (signed 4-byte, max 2_147_483_647). Real-world lesson
 * traversals where a student left the block open for days produced
 * durations larger than that ceiling and the backfill crashed with
 * `numeric value out of range`.
 *
 * Widen the column to `bigint` so even multi-day durations fit. The
 * backfill code separately clamps absurd values to 24 h so they don't
 * pollute the export, but the wider column protects us from any other
 * write path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used in the test suite) doesn't support ALTER COLUMN ... TYPE.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement('ALTER TABLE elo_interactions ALTER COLUMN duration_ms TYPE bigint');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement('ALTER TABLE elo_interactions ALTER COLUMN duration_ms TYPE integer');
    }
};
