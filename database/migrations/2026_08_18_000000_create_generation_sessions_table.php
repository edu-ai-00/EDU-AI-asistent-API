<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived sessions for the AI Course Assistant (BR-D4PE8R).
 *
 * An admin opens the "Generovat" dialog in the course editor, which creates a
 * session and renders its token as a QR code. The phone that scans the QR can
 * upload photos WITHOUT authenticating — the token itself is the credential,
 * so it is stored hashed (like a Sanctum token), scoped to a single session and
 * expires within minutes. Uploaded attachments live in R2 under
 * generation/{id}/ and are deleted together with the session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('course_id')->nullable();
            $table->string('course_name')->nullable();
            // collecting → generating → done | failed
            $table->string('status', 20)->default('collecting');
            // ocr | full — chosen in step 2 of the wizard
            $table->string('mode', 20)->nullable();
            $table->json('attachments')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_sessions');
    }
};
