<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for BR-N23J4S: an empty database must migrate with
 * ADMIN_EMAILS set. The 2026-03 admin seed migration created the first admin
 * through User::create, whose saving hook writes invited_at — a column the
 * 2026-09 team-invite migration adds — so the Railway dev environment crashed
 * on its first deploy. phpunit.xml blanks ADMIN_EMAILS, which is why the
 * rest of the suite never ran that branch.
 *
 * No RefreshDatabase: the test migrates its own in-memory database.
 */
class SeedInitialAdminMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setAdminEmails('');

        parent::tearDown();
    }

    public function test_a_fresh_database_migrates_and_seeds_the_first_admin(): void
    {
        // The migration reads env() directly, not config, so set the env.
        $this->setAdminEmails('first-admin@example.test, second-admin@example.test');

        $this->artisan('migrate:fresh')->assertExitCode(0);

        $admins = DB::table('users')->get();
        $this->assertCount(1, $admins);
        $this->assertSame('first-admin@example.test', $admins[0]->email);
        $this->assertSame('admin', $admins[0]->role);
        $this->assertNotNull($admins[0]->email_verified_at);
    }

    private function setAdminEmails(string $value): void
    {
        putenv("ADMIN_EMAILS={$value}");
        $_ENV['ADMIN_EMAILS'] = $value;
        $_SERVER['ADMIN_EMAILS'] = $value;
    }
}
