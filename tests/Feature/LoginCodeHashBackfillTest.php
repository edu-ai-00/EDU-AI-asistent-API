<?php

namespace Tests\Feature;

use App\Support\StudentCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for migration 2026_08_13_000006_backfill_login_code_hash.php.
 * RefreshDatabase already runs it once per test as part of schema setup,
 * against an empty users table (a no-op) — these tests invoke its up()/down()
 * directly against rows inserted afterwards, the way a production backfill
 * against pre-existing data actually exercises it.
 */
class LoginCodeHashBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_13_000006_backfill_login_code_hash.php');
    }

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

    public function test_backfills_hash_for_every_coded_user_without_one(): void
    {
        $id = $this->insertRawUser('School', 'AB12CD');

        $this->migration()->up();

        $this->assertSame(
            StudentCodes::hash('AB12CD'),
            DB::table('users')->where('id', $id)->value('login_code_hash'),
        );
    }

    public function test_does_not_touch_rows_without_a_login_code(): void
    {
        $guestId = $this->insertRawUser('Guest', null);

        $this->migration()->up();

        $this->assertNull(DB::table('users')->where('id', $guestId)->value('login_code_hash'));
    }

    public function test_is_idempotent_on_rerun(): void
    {
        $id = $this->insertRawUser('School', 'AB12CD');

        $this->migration()->up();
        $this->migration()->up(); // must not throw, must not change the result

        $this->assertSame(
            StudentCodes::hash('AB12CD'),
            DB::table('users')->where('id', $id)->value('login_code_hash'),
        );
    }

    /**
     * BLOCKING regression test (reviewer cross-wire finding). Two live codes
     * that hash identically once Crockford-normalised (I/L→1, O→0) must not
     * abort the whole migration — throwing aborts start.sh's
     * `migrate --force` under set -e and takes the entire API down on every
     * restart. But leaving the FIRST-processed row's hash in place while
     * skipping the second is worse than either: CodeController's lookup is
     * a single WHERE login_code_hash = ?, so the second student typing
     * THEIR OWN code would be silently authenticated onto the first
     * student's account — their courses, progress, chats included. Before
     * this migration ran, both had a working, unambiguous plaintext login;
     * neither was "already ambiguous". The only correct outcome is that
     * BOTH lose code login until a human reissues one of them — proven here
     * not just by checking the hash columns but by actually POSTing both
     * codes to /api/resolve-code and confirming neither resolves to
     * anybody, let alone the wrong body.
     */
    public function test_a_collision_locks_out_both_accounts_instead_of_cross_wiring_them(): void
    {
        Log::spy();

        $annaId = $this->insertRawUser('Anna', 'ABC012');
        $baraId = $this->insertRawUser('Bara', 'ABCO12'); // hashes the same as ABC012 once normalised
        $thirdId = $this->insertRawUser('Third', 'ZZ9988'); // unrelated — must still be backfilled

        $this->migration()->up();

        $this->assertNull(
            DB::table('users')->where('id', $annaId)->value('login_code_hash'),
            'the earlier-processed row must lose its hash too, not keep it',
        );
        $this->assertNull(
            DB::table('users')->where('id', $baraId)->value('login_code_hash'),
            'the later-processed row must never have been given a hash',
        );
        $this->assertSame(
            StudentCodes::hash('ZZ9988'),
            DB::table('users')->where('id', $thirdId)->value('login_code_hash'),
            'an unrelated row must be unaffected by a collision elsewhere in the table',
        );

        // The actual security property: Bára typing her OWN code must not
        // land her on Anna's account (or anybody else's).
        $baraTypesHerOwnCode = $this->postJson('/api/resolve-code', ['code' => 'ABCO12']);
        $baraTypesHerOwnCode->assertStatus(404);

        $annaTypesHerOwnCode = $this->postJson('/api/resolve-code', ['code' => 'ABC012']);
        $annaTypesHerOwnCode->assertStatus(404);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) use ($annaId, $baraId) {
                $encoded = (string) json_encode($context);

                return $message === 'consent.backfill.login_code_hash_collision'
                    && ($context['user_id'] ?? null) === $baraId
                    && ($context['other_user_id'] ?? null) === $annaId
                    && ! str_contains($encoded, 'ABCO12')
                    && ! str_contains($encoded, 'ABC012');
            });
    }

    /**
     * Reviewer-caught regression guard. Swapping array_key_exists($hash,
     * $ownerByHash) for isset(...) — the exact mistake the migration's own
     * comment warns against — let the entire suite above stay green while
     * the cross-wire came back three-way: isset() treats a value of null
     * as "not set", so once the first collision neutralises a hash to
     * null, a THIRD colliding code sails straight through the check and
     * silently claims it. Three distinct plaintext codes that all fold to
     * the same hash under Crockford normalisation (I/L/1 all become '1')
     * must all end with no hash, and all three codes must 404 — not just
     * the first pair.
     */
    public function test_a_three_way_collision_locks_out_all_three_accounts(): void
    {
        $codes = ['ABCI12', 'ABCL12', 'ABC112']; // I, L, 1 all normalise to the same '1'
        $ids = array_map(fn (string $code) => $this->insertRawUser($code, $code), $codes);

        $this->migration()->up();

        foreach ($ids as $id) {
            $this->assertNull(
                DB::table('users')->where('id', $id)->value('login_code_hash'),
                "user #{$id} must end with no hash — a three-way collision must lock out every account, not just two",
            );
        }

        foreach ($codes as $code) {
            $this->postJson('/api/resolve-code', ['code' => $code])->assertStatus(404);
        }
    }

    public function test_a_collision_does_not_roll_back_unrelated_rows_in_the_same_chunk(): void
    {
        // Sanity check that a collision does not roll back the whole chunk
        // it was found in (an earlier version of this migration wrapped the
        // entire run in one transaction and threw on collision, which DID
        // roll everything back — this proves that is gone).
        $before = $this->insertRawUser('Before', 'AA1111');
        $collideA = $this->insertRawUser('CollideA', 'ABC012');
        $collideB = $this->insertRawUser('CollideB', 'ABCO12');

        $this->migration()->up();

        $this->assertNotNull(
            DB::table('users')->where('id', $before)->value('login_code_hash'),
            'a row unrelated to any collision, in the same chunk, must still be backfilled',
        );
        $this->assertNull(DB::table('users')->where('id', $collideA)->value('login_code_hash'));
        $this->assertNull(DB::table('users')->where('id', $collideB)->value('login_code_hash'));
    }

    /**
     * A collision must also be caught against a hash that already existed
     * BEFORE this migration ran at all — not only one discovered earlier in
     * the same run. A minor's login_code_hash is exactly such a hash: it
     * was never derived from this migration, but it is just as ambiguous
     * with a colliding legacy school code, and leaving it live would let
     * the school student's code log in as the child. Also asserts the
     * distinct log message: a minor's hash is not recoverable by a teacher
     * re-reading a plaintext column the way a school code is — the
     * guardian has to regenerate it from the manage page — so an operator
     * must be able to tell this case apart from the generic one from the
     * log line alone.
     */
    public function test_a_collision_against_a_pre_existing_minor_hash_clears_it_too(): void
    {
        $minorId = $this->insertRawUser('Stydlivý delfín', null, StudentCodes::hash('ABC012'), true);
        $schoolId = $this->insertRawUser('School', 'ABCO12'); // hashes the same as the minor's code

        // Two distinct calls are expected: the minor-specific one (this is
        // what this test exists to guard) AND the generic one every
        // collision still gets. Two separate pre-registered expectations,
        // not Log::spy() + a single ->once() assertion — that would demand
        // exactly one TOTAL call to critical() and fail here regardless of
        // which message it checked, since there really are two.
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(function (string $message, array $context) use ($minorId, $schoolId) {
                $encoded = (string) json_encode($context);

                return $message === 'consent.backfill.login_code_hash_collision_minor_victim'
                    && ($context['minor_user_id'] ?? null) === $minorId
                    && ($context['other_user_id'] ?? null) === $schoolId
                    && ! str_contains($encoded, 'ABC012')
                    && ! str_contains($encoded, 'ABCO12');
            });

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(function (string $message, array $context) use ($minorId, $schoolId) {
                return $message === 'consent.backfill.login_code_hash_collision'
                    && ($context['user_id'] ?? null) === $schoolId
                    && ($context['other_user_id'] ?? null) === $minorId;
            });

        $this->migration()->up();

        $this->assertNull(
            DB::table('users')->where('id', $minorId)->value('login_code_hash'),
            "a pre-existing minor hash must be cleared when a legacy code collides with it — it is no longer unambiguous",
        );
        $this->assertNull(DB::table('users')->where('id', $schoolId)->value('login_code_hash'));
    }

    /** The generic collision event must NOT fire the minor-specific one when neither side is a minor. */
    public function test_a_collision_between_two_school_codes_never_logs_the_minor_victim_message(): void
    {
        Log::spy();

        $this->insertRawUser('Anna', 'ABC012');
        $this->insertRawUser('Bara', 'ABCO12');

        $this->migration()->up();

        Log::shouldNotHaveReceived('critical', function (string $message) {
            return $message === 'consent.backfill.login_code_hash_collision_minor_victim';
        });
    }

    /**
     * End-to-end: a school student created before this task existed only
     * had a plaintext login_code. After the backfill runs, they must be
     * able to log in via /api/resolve-code, which now looks up ONLY by
     * login_code_hash — proving the backfilled hash is actually usable, not
     * just present in the column.
     */
    public function test_a_school_student_logs_in_by_code_after_the_backfill(): void
    {
        $id = $this->insertRawUser('School', 'AB12CD');

        $this->migration()->up();

        $response = $this->postJson('/api/resolve-code', ['code' => 'AB12CD']);

        $response->assertOk();
        $response->assertJsonPath('type', 'user');
        $response->assertJsonPath('user.id', $id);
    }

    public function test_down_clears_hash_only_for_rows_that_still_have_a_plaintext_code(): void
    {
        $schoolId = $this->insertRawUser('School', 'AB12CD');
        $minorId = $this->insertRawUser('Minor', null, StudentCodes::hash('ZZZZZZ'), true);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertNull(
            DB::table('users')->where('id', $schoolId)->value('login_code_hash'),
            'down() must undo what up() wrote for a coded school account',
        );
        $this->assertNotNull(
            DB::table('users')->where('id', $minorId)->value('login_code_hash'),
            "down() must never touch a minor's hash — it is their only credential",
        );
    }
}
