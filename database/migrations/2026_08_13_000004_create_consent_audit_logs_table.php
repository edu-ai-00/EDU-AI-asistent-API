<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_audit_logs', function (Blueprint $table) {
            $table->id();
            // nullOnDelete: the audit trail must outlive the consent it describes.
            $table->foreignId('consent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            // requested | confirmed | failed_attempt | codes_sent |
            // code_regenerated | revoked | purged | link_sent
            $table->string('event', 40);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['consent_id', 'event']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_audit_logs');
    }
};
