<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vector_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vector_id');
            $table->integer('dimension_index');
            $table->string('code', 20)->nullable();
            $table->string('name');
            $table->string('domain_code', 10)->nullable();
            $table->string('domain_name')->nullable();
            $table->string('construct_name')->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('vector_id')->references('id')->on('skill_vectors')->cascadeOnDelete();
            $table->unique(['vector_id', 'dimension_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vector_dimensions');
    }
};
