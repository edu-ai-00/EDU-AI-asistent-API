<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Laravel creates a CHECK constraint for enum columns on Postgres.
            // Drop the old constraint and add a new one with 'private' included.
            DB::statement("ALTER TABLE courses DROP CONSTRAINT IF EXISTS courses_status_check");
            DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_status_check CHECK (status::text = ANY (ARRAY['draft'::text, 'locked'::text, 'approved'::text, 'published'::text, 'private'::text]))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE courses DROP CONSTRAINT IF EXISTS courses_status_check");
            DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_status_check CHECK (status::text = ANY (ARRAY['draft'::text, 'locked'::text, 'approved'::text, 'published'::text]))");
        }
    }
};
