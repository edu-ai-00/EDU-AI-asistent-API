<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            // Opaque identifier that travels in the emailed confirmation link.
            $table->uuid('public_id')->unique();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            // pending | confirmed | revoked | expired
            $table->string('status', 20)->default('pending');
            $table->string('text_version', 40);
            $table->string('locale', 5)->default('cs');
            $table->string('country', 2)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Set on revocation; the purge command erases data once it passes.
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();

            $table->index(['status', 'purge_after']);
            $table->index(['guardian_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
