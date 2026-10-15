<?php

namespace App\Console\Commands;

use App\Support\StudentCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only pre-flight check for migration
 * 2026_08_13_000006_backfill_login_code_hash.php. Two plaintext login codes
 * can hash to the same value once Crockford-normalised (I/L→1, O→0) — e.g.
 * ABC012 and ABCO12 — and that migration NEUTRALISES (does not fail) any
 * such pair by clearing login_code_hash on every account it is ambiguous
 * with, leaving all of them without code login.
 *
 * Checks two sources, not just login_code: every account that ALREADY has a
 * login_code_hash (typically minors) is loaded too, alongside every school
 * code hashed fresh. Checking only login_code would miss a minor-vs-school
 * collision entirely — a minor's login_code is always NULL, so they would
 * never appear in that scan, yet losing that hash means losing their SOLE
 * credential, the one collision outcome with no plaintext fallback for the
 * victim to recover from (BR-8FDF37).
 *
 * Run this against production before deploying the migration so a collision
 * is a decision an operator makes on purpose — regenerate one of the two
 * codes in admin, or the child's code via the guardian manage page — rather
 * than something discovered afterwards from a locked-out account.
 *
 *   php artisan consent:check-code-collisions
 *
 * Writes nothing. Prints user ids only, never the codes themselves, so
 * running this against production and pasting the output somewhere does not
 * itself leak a credential.
 */
class ConsentCheckCodeCollisions extends Command
{
    protected $signature = 'consent:check-code-collisions';

    protected $description = 'Report every login code (and existing hash) that would collide in the login_code_hash backfill, without writing anything';

    public function handle(): int
    {
        // hash => [id => true]. A set, not a list — a school account that
        // already carries both a matching login_code and login_code_hash
        // would otherwise be counted against itself once by each loop
        // below and misreported as its own collision.
        $idsByHash = [];

        $add = function (string $hash, int $id) use (&$idsByHash): void {
            $idsByHash[$hash][$id] = true;
        };

        // Source 1: every account that already has a hash — minors, and
        // any school account already backfilled by a previous run. This is
        // what makes a minor visible to this command at all: their
        // login_code is always NULL.
        foreach (
            DB::table('users')->whereNotNull('login_code_hash')->orderBy('id')->get(['id', 'login_code_hash'])
            as $row
        ) {
            $add($row->login_code_hash, $row->id);
        }

        // Source 2: every school code, hashed fresh regardless of whether
        // it already has a stored hash — catches a collision against
        // another school code too.
        foreach (
            DB::table('users')->whereNotNull('login_code')->orderBy('id')->get(['id', 'login_code'])
            as $row
        ) {
            $add(StudentCodes::hash($row->login_code), $row->id);
        }

        $collisions = array_values(array_filter(
            array_map('array_keys', $idsByHash),
            fn (array $ids) => count($ids) > 1,
        ));

        if ($collisions === []) {
            $this->info('No colliding login codes found.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d colliding code group(s) found:', count($collisions)));

        foreach ($collisions as $ids) {
            sort($ids);
            $this->line('  user ids: '.implode(', ', $ids));
        }

        return self::FAILURE;
    }
}
