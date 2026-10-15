<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consent_id')->constrained()->cascadeOnDelete();
            // confirm = 6-char code typed by the parent; manage = magic-link token
            $table->string('purpose', 20);
            // HMAC only. There is deliberately no plaintext column here.
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index(['code_hash', 'purpose']);
            $table->index(['consent_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_codes');
    }
};
