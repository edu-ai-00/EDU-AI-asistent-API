<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-DAB49K — the RAG provider asked the chat to send a `domains` filter
 * with Cermatík's questions ("legal_entity": "ZS_KAVCI_HORY_ZS",
 * "user_role": "STUDENT", "domains": ["SECONDARY_ADMISSIONS"]).
 *
 * Školník and Cermatík search the same legal entity; the domains list is
 * what narrows Cermatík to the admissions documents. Like rag_legal_entity
 * it lives on the mentor row so the admin can change it without a deploy.
 * null = no filter, i.e. the request carries no `domains` key at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentors', function (Blueprint $table) {
            $table->json('rag_domains')->nullable()->after('rag_legal_entity');
        });

        DB::table('mentors')
            ->where('slug', 'cermatik')
            ->whereNull('rag_domains')
            ->update(['rag_domains' => json_encode(['SECONDARY_ADMISSIONS'])]);
    }

    public function down(): void
    {
        Schema::table('mentors', function (Blueprint $table) {
            $table->dropColumn('rag_domains');
        });
    }
};
