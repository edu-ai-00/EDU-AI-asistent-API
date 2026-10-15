<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User::booted()'s saving-hook keeps login_code_hash in sync with
 * login_code — the mechanism that lets CodeController::resolve() look up
 * ONLY by hash without every write path having to remember both columns
 * (BR-8FDF37).
 */
class UserLoginCodeHashSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_login_code_computes_the_hash_automatically(): void
    {
        $user = User::create(['name' => 'Student', 'login_code' => 'AB12CD']);

        $this->assertSame(StudentCodes::hash('AB12CD'), $user->fresh()->login_code_hash);
    }

    public function test_changing_login_code_recomputes_the_hash(): void
    {
        $user = User::create(['name' => 'Student', 'login_code' => 'AB12CD']);

        $user->update(['login_code' => 'ZZ99YY']);

        $this->assertSame(StudentCodes::hash('ZZ99YY'), $user->fresh()->login_code_hash);
    }

    /**
     * I3 regression test. The hook used to only react when login_code was
     * being SET to a non-null value; nulling it left the old hash in place,
     * so a deleted code kept authenticating — a credential outliving its
     * own deletion, inside the one hook whose entire purpose is keeping
     * these two columns in sync.
     */
    public function test_nulling_login_code_clears_the_hash_too(): void
    {
        $user = User::create(['name' => 'Student', 'login_code' => 'AB12CD']);
        $this->assertNotNull($user->fresh()->login_code_hash);

        $user->update(['login_code' => null]);

        $this->assertNull($user->fresh()->login_code_hash);

        // The strongest form of the assertion: the OLD code must no longer
        // resolve a login at all, not just "the column looks null".
        $this->postJson('/api/resolve-code', ['code' => 'AB12CD'])->assertStatus(404);
    }

    /**
     * I4 regression test. An explicit login_code_hash write in the same
     * save must win outright — the hook must not recompute and silently
     * overwrite a CORRECT explicit value a moment later. This is what
     * keeps AdminClassroomController's explicit
     * 'login_code_hash' => StudentCodes::hash($loginCode) write meaningful
     * instead of dead code that only looks like the invariant.
     */
    public function test_a_matching_explicit_login_code_hash_write_is_not_overwritten_by_the_hook(): void
    {
        $user = User::create([
            'name' => 'Student',
            'login_code' => 'AB12CD',
            'login_code_hash' => StudentCodes::hash('AB12CD'),
        ]);

        $this->assertSame(StudentCodes::hash('AB12CD'), $user->fresh()->login_code_hash);
    }

    /**
     * I4 mismatch-hardening regression test. Standing down for ANY explicit
     * login_code_hash write — not only one that actually matches
     * hash(login_code) — would let a caller create an account whose
     * readable code and whose actual credential are two different strings:
     * it would authenticate under a code the teacher can never read
     * (login_code stays 'JK56MN') and fail under the one they can. Nothing
     * reaches this today — every write path assigns a matching pair
     * explicitly — but the hook must make an inconsistent pair
     * structurally impossible, not merely unreached: a mismatched explicit
     * hash is not trusted and gets overwritten with the correct one,
     * derived from login_code.
     */
    public function test_a_mismatched_explicit_login_code_hash_is_self_healed(): void
    {
        $decoyHash = StudentCodes::hash('PQ78RS'); // hash of a DIFFERENT code than login_code below

        $user = User::create([
            'name' => 'Student',
            'login_code' => 'JK56MN',
            'login_code_hash' => $decoyHash,
        ]);

        $this->assertSame(StudentCodes::hash('JK56MN'), $user->fresh()->login_code_hash);
        $this->assertNotSame($decoyHash, $user->fresh()->login_code_hash);

        // The strongest form of the assertion: the code that actually
        // reaches the database (login_code) must be the one that logs in —
        // not the decoy hash that was never a real code anyone was told.
        $this->postJson('/api/resolve-code', ['code' => 'JK56MN'])->assertOk();
    }

    /** A minor's login_code is never set, so the hook must never touch it. */
    public function test_hook_never_touches_a_minor_created_with_only_a_hash(): void
    {
        $hash = StudentCodes::hash('ZZZZZZ');

        $user = User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'login_code_hash' => $hash,
        ]);

        $this->assertSame($hash, $user->fresh()->login_code_hash);
        $this->assertNull($user->fresh()->login_code);
    }
}
