<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds columns needed for R2 storage and course metadata display:
     * - description, author, emoji: Display metadata for Knihovna
     * - lesson_count, estimated_minutes: Quick stats from JSON
     * - file_path, file_size, file_uploaded_at: R2 storage tracking
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('name');
            $table->string('author')->nullable()->after('description');
            $table->string('emoji', 10)->nullable()->after('author');
            $table->integer('lesson_count')->default(0)->after('emoji');
            $table->integer('estimated_minutes')->default(0)->after('lesson_count');
            $table->string('file_path')->nullable()->after('data');
            $table->bigInteger('file_size')->nullable()->after('file_path');
            $table->timestamp('file_uploaded_at')->nullable()->after('file_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'author',
                'emoji',
                'lesson_count',
                'estimated_minutes',
                'file_path',
                'file_size',
                'file_uploaded_at',
            ]);
        });
    }
};
