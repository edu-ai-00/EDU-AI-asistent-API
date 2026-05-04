<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->uuid('vector_id')->nullable()->after('created_by');
            $table->foreign('vector_id')->references('id')->on('skill_vectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['vector_id']);
            $table->dropColumn('vector_id');
        });
    }
};
