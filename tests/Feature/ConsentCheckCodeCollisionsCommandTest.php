<?php

namespace Tests\Feature;

use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan consent:check-code-collisions` — the C2 pre-flight check
 * meant to be run against production before deploying the login_code_hash
 * backfill migration, so a hash collision is caught and decided on purpose
 * rather than discovered from a locked-out teacher. It must be read-only.
 */
class ConsentCheckCodeCollisionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function insertRawUser(string $name, ?string $loginCode, ?string $loginCodeHash = null, bool $isMinor = false): int
    {
        return DB::table('users')->insertGetId([
            'name' => $name,
            'login_code' => $loginCode,
            'login_code_hash' => $loginCodeHash,
            'is_guest' => false,
            'is_minor' => $isMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reports_success_when_no_codes_collide(): void
    {
        $this->insertRawUser('A', 'AA1111');
        $this->insertRawUser('B', 'BB2222');

        $this->artisan('consent:check-code-collisions')
            ->assertSuccessful();
    }

    public function test_reports_failure_and_ids_when_two_codes_collide(): void
    {
        $firstId = $this->insertRawUser('First', 'ABC012');
        $secondId = $this->insertRawUser('Second', 'ABCO12');

        // A single combined substring, not one expectation per id: Mockery
        // dispatches each written line to the FIRST matching expectation,
        // so a separate expectsOutputToContain((string) $firstId) can
        // "steal" the line that also contains $secondId (both ids appear on
        // the same "user ids: X, Y" line) and starve the second assertion —
        // this is not a hypothetical, it is what happened when this test
        // was first written with two separate calls.
        $this->artisan('consent:check-code-collisions')
            ->assertFailed()
            ->expectsOutputToContain("user ids: {$firstId}, {$secondId}");
    }

    public function test_never_prints_the_plaintext_codes(): void
    {
        $this->insertRawUser('First', 'ABC012');
        $this->insertRawUser('Second', 'ABCO12');

        $this->artisan('consent:check-code-collisions')
            ->assertFailed()
            ->doesntExpectOutputToContain('ABC012')
            ->doesntExpectOutputToContain('ABCO12');
    }

    public function test_writes_nothing_to_the_database(): void
    {
        $firstId = $this->insertRawUser('First', 'ABC012');
        $secondId = $this->insertRawUser('Second', 'ABCO12');

        $this->artisan('consent:check-code-collisions')->run();

        $this->assertNull(DB::table('users')->where('id', $firstId)->value('login_code_hash'));
        $this->assertNull(DB::table('users')->where('id', $secondId)->value('login_code_hash'));
    }

    public function test_ignores_users_without_a_login_code(): void
    {
        $this->insertRawUser('Guest', null);

        $this->artisan('consent:check-code-collisions')
            ->assertSuccessful();
    }

    /**
     * Regression test: the command used to scan ONLY login_code, so a
     * minor — whose login_code is always NULL — never appeared in it at
     * all. A minor-vs-school collision (the one case where the victim
     * loses their SOLE credential) exited 0 with "no collisions found"
     * even though the migration would neutralise the minor's hash the
     * moment it ran (BR-8FDF37).
     */
    public function test_reports_a_collision_where_the_victim_is_a_minor(): void
    {
        $minorId = $this->insertRawUser('Stydlivý delfín', null, StudentCodes::hash('ABC012'), true);
        $schoolId = $this->insertRawUser('School', 'ABCO12'); // hashes the same as the minor's code

        $this->artisan('consent:check-code-collisions')
            ->assertFailed()
            ->expectsOutputToContain("user ids: {$minorId}, {$schoolId}");
    }

    /**
     * A school account that already has BOTH a matching login_code and
     * login_code_hash (e.g. backfilled by a previous run) must not be
     * reported as colliding with itself — it appears once via the
     * already-hashed scan and once via the login_code scan, and both
     * resolve to the SAME id.
     */
    public function test_an_already_backfilled_account_does_not_collide_with_itself(): void
    {
        $this->insertRawUser('School', 'AA1111', StudentCodes::hash('AA1111'));

        $this->artisan('consent:check-code-collisions')
            ->assertSuccessful();
    }
}
