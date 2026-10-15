<?php

use App\Support\StudentCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Backfill login_code_hash for every user that already has a plaintext
     * login_code — school students created before minor accounts existed.
     * After this task CodeController::resolve() looks up ONLY by
     * login_code_hash, so a code with no hash would become unreachable; the
     * plaintext itself is untouched, since a teacher must still be able to
     * read it back in admin (BR-8FDF37).
     *
     * Idempotent: only rows where login_code_hash IS NULL are touched, so a
     * re-run after a partial failure (or a chunk that hit a collision, see
     * below) just skips everything already done. Rows where login_code IS
     * NULL (minors, guests, email/OAuth accounts) are never touched by the
     * WHERE clause that selects backfill candidates — but a minor's hash
     * CAN still be cleared as the "previous holder" in a collision (see
     * below); that is not a bug, it is the same fail-closed rule applied to
     * every account, minors included.
     *
     * Run `php artisan consent:check-code-collisions` against production
     * BEFORE deploying this migration — it reports every colliding pair
     * this migration would neutralise, read-only, so that is a decision an
     * operator makes on purpose rather than something discovered from a
     * student who landed on a stranger's account (BR-8FDF37 C2).
     */
    public function up(): void
    {
        // hash => ['id' => ..., 'is_minor' => ...] of whichever row
        // currently holds it, or null once neutralised. Seeded from every
        // existing hash — minors included, not just hashes this migration
        // writes — because a collision against ANY current holder, not only
        // one discovered earlier in this same run, must be caught. is_minor
        // travels alongside the id so a minor victim can be logged
        // distinctly below, without a second query per collision. Kept in
        // memory and mutated as rows are backfilled (or un-backfilled, on a
        // collision) below, rather than re-queried per row.
        $ownerByHash = [];

        foreach (
            DB::table('users')->whereNotNull('login_code_hash')->orderBy('id')->get(['id', 'login_code_hash', 'is_minor'])
            as $row
        ) {
            $ownerByHash[$row->login_code_hash] = ['id' => $row->id, 'is_minor' => (bool) $row->is_minor];
        }

        // Chunked, not a single ->get(): this runs from start.sh with
        // set -e, before `php artisan serve` — a single SELECT of every
        // coded user plus one giant write transaction would both risk
        // memory exhaustion at scale and hold the deploy's boot blocked on
        // one long-running transaction (BR-8FDF37 I5). Each chunk commits
        // independently; that costs the "all-or-nothing" rollback an
        // earlier version of this migration had, but nothing here still
        // needs it — a collision is neutralised in place, not thrown (next
        // paragraph), so there is no failure state left for a rollback to
        // protect against.
        DB::table('users')
            ->whereNotNull('login_code')
            ->whereNull('login_code_hash')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$ownerByHash) {
                DB::transaction(function () use ($rows, &$ownerByHash) {
                    foreach ($rows as $row) {
                        $hash = StudentCodes::hash($row->login_code);

                        // array_key_exists, not isset: once a hash is
                        // neutralised below its owner is set to null, and
                        // isset() treats a null value as "not set" — using
                        // it here would let a THIRD colliding row sail
                        // through and silently claim the hash outright.
                        if (array_key_exists($hash, $ownerByHash)) {
                            $previousOwner = $ownerByHash[$hash];

                            // Two live codes hash to the same value once
                            // Crockford-normalised (I/L→1, O→0) — e.g.
                            // ABC012 and ABCO12. Before this migration ran,
                            // an exact plaintext lookup meant BOTH accounts
                            // had a working, unambiguous login — neither was
                            // "already ambiguous". After backfilling only
                            // one side, CodeController's hash-only lookup
                            // (WHERE login_code_hash = ?) cannot tell the
                            // two codes apart: the student who types the
                            // OTHER account's code is silently logged into
                            // it — their courses, progress, chats included
                            // (reviewer-verified cross-wire, BR-8FDF37).
                            // Throwing here — an earlier version of this
                            // migration did — aborts start.sh's
                            // `migrate --force` under set -e and takes the
                            // whole API down on every restart. The correct
                            // middle ground is to null the PREVIOUS holder's
                            // hash too (if it still has one — a third
                            // colliding row finds this already done and has
                            // nothing left to undo), so the code 404s for
                            // every account it is ambiguous with instead of
                            // resolving to whichever one happened to be
                            // processed first. That is a real regression
                            // from before this migration (both had working
                            // logins; now neither does) but it is the only
                            // outcome that does not hand one student's data
                            // to another — an operator reissues one of the
                            // colliding codes in admin and both work again.
                            // consent:check-code-collisions finds these
                            // ahead of a deploy, ids only, never the codes.
                            if ($previousOwner !== null) {
                                DB::table('users')->where('id', $previousOwner['id'])->update(['login_code_hash' => null]);
                            }

                            // A minor's hash, unlike a school student's, is
                            // not recoverable by a teacher re-reading a
                            // plaintext column — it has none. The guardian
                            // has to regenerate it from the manage page, and
                            // nothing else tells anyone that needs to
                            // happen. Logged under a distinct message (ids
                            // only, same as the generic event) so an
                            // operator scanning logs can tell the two cases
                            // apart and prioritise the minor one without
                            // having to cross-reference against the users
                            // table first.
                            if (($previousOwner['is_minor'] ?? false) === true) {
                                Log::critical('consent.backfill.login_code_hash_collision_minor_victim', [
                                    'minor_user_id' => $previousOwner['id'],
                                    'other_user_id' => $row->id,
                                ]);
                            }

                            Log::critical('consent.backfill.login_code_hash_collision', [
                                'user_id' => $row->id,
                                'other_user_id' => $previousOwner['id'] ?? null,
                            ]);

                            $ownerByHash[$hash] = null;

                            continue;
                        }

                        DB::table('users')->where('id', $row->id)->update(['login_code_hash' => $hash]);
                        $ownerByHash[$hash] = ['id' => $row->id, 'is_minor' => false];
                    }
                });
            });
    }

    /**
     * Deliberately narrow, not a blanket "null the column": a minor account's
     * login_code_hash is its ONLY credential (login_code is always null for
     * is_minor=true accounts), so clearing every hash here would lock every
     * minor out permanently with no way back in — down() is meant to undo
     * what up() did, not erase data up() never touched.
     *
     * up() only ever WRITES a hash for rows that still carry a plaintext
     * login_code (a minor's hash is only ever cleared by up(), never set),
     * so restricting the rollback to exactly those rows is enough to
     * reverse it and can never touch a minor's row.
     *
     * Rolling back this migration ALONE is a full school-login outage:
     * CodeController::resolve() looks up ONLY by login_code_hash, so a
     * school student whose hash this clears cannot log in again until the
     * migration (or an equivalent code rollback) is reapplied. This must be
     * paired with rolling back CodeController's hash-only lookup, not run
     * on its own against a live deploy.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereNotNull('login_code')
            ->update(['login_code_hash' => null]);
    }
};
