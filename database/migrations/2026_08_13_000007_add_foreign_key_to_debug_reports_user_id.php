<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * debug_reports.user_id was declared with ->nullOnDelete() but never
     * ->constrained() (see 2026_02_27_300000_create_debug_reports_table.php)
     * — Fluent silently drops nullOnDelete() when there is no foreign key to
     * attach it to, so the column has never actually been constrained. In
     * practice this let an erased user's row keep pointing at a deleted id
     * instead of being nulled (BR-8FDF37 I3): StudentDataEraser now deletes
     * a child's debug_reports outright rather than relying on this FK, but
     * the column should still tell the truth for every other deletion path.
     */
    public function up(): void
    {
        // A dangling user_id (pointing at an id that no longer exists) is
        // possible precisely because no constraint has ever enforced
        // referential integrity here. Null those out first so the ALTER
        // below does not fail against existing data. whereNotExists, not
        // whereNotIn: whereNotIn's subquery has to materialise every users.id
        // and hold it for the comparison, which MySQL's optimizer turns into
        // a dependent subquery (re-executed per debug_reports row) rather
        // than a semi-join — fine at today's row counts, needlessly
        // expensive if this table ever grows. NOT EXISTS is what MySQL
        // actually optimises well for this shape.
        DB::table('debug_reports')
            ->whereNotNull('user_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'debug_reports.user_id');
            })
            ->update(['user_id' => null]);

        Schema::table('debug_reports', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debug_reports', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
