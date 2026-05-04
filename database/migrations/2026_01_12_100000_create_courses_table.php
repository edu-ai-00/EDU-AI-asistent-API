<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('course_id')->unique();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft', 'locked', 'approved', 'published'])->default('draft');
            $table->string('language', 10)->default('en');
            $table->json('data');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('language');
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
