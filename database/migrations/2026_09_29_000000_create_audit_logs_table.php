<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One audit trail for every Advanced User Management action (BR-BNNASY).
 *
 * Deliberately no foreign keys. An audit row is append-only: a cascade would
 * delete it, a nullOnDelete would rewrite it, and a restrictOnDelete would
 * make StudentDataEraser throw for any child who ever appeared here. The ids
 * are kept as plain numbers instead, and the configurable retention
 * (config('audit.retention_days')) is what bounds how long they live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('actor_id')->nullable();
            // admin | teacher | student | guardian | api_key | system
            $table->string('actor_role', 20)->nullable();

            // Dotted key, e.g. classroom.updated, consent.revoked
            $table->string('action', 64);
            // success | denied (a refused attempt that is still worth a row)
            $table->string('outcome', 16)->default('success');

            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            // Scope the action happened in — what an admin filters by.
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('classroom_id')->nullable();

            // admin (school/teacher via the panel) | self_service | system
            $table->string('source', 20);

            $table->json('diff')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('school_id');
            $table->index('classroom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
