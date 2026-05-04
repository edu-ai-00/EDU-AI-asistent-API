<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_skill_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('course_id');
            $table->uuid('vector_id');
            $table->json('formula_json');
            $table->float('display_scale_min')->default(1.0);
            $table->float('display_scale_max')->default(10.0);
            $table->float('confidence_c')->default(2.5);
            $table->integer('min_count')->default(10);
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('vector_id')->references('id')->on('skill_vectors')->cascadeOnDelete();
            $table->unique('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_skill_configs');
    }
};
