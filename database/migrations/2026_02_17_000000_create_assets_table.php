<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('course_id')->index();
            $table->string('filename');
            $table->string('path');
            $table->string('url');
            $table->unsignedBigInteger('size');
            $table->string('mime_type');
            $table->timestamps();

            $table->foreign('course_id')
                ->references('course_id')
                ->on('courses')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
