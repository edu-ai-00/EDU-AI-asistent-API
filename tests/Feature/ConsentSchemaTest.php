<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConsentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_tables_exist_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('guardians'));
        $this->assertTrue(Schema::hasColumns('guardians', ['email', 'locale', 'country']));

        $this->assertTrue(Schema::hasTable('consents'));
        $this->assertTrue(Schema::hasColumns('consents', [
            'public_id', 'guardian_id', 'status', 'text_version',
            'confirmed_at', 'revoked_at', 'purge_after',
        ]));

        $this->assertTrue(Schema::hasTable('consent_codes'));
        $this->assertTrue(Schema::hasColumns('consent_codes', [
            'consent_id', 'purpose', 'code_hash', 'attempts',
            'expires_at', 'used_at', 'invalidated_at',
        ]));

        $this->assertTrue(Schema::hasTable('consent_audit_logs'));
        $this->assertTrue(Schema::hasColumns('consent_audit_logs', [
            'consent_id', 'guardian_id', 'event', 'ip', 'meta',
        ]));
    }

    public function test_consent_codes_table_stores_no_plaintext_column(): void
    {
        $this->assertFalse(Schema::hasColumn('consent_codes', 'code'));
    }
}
