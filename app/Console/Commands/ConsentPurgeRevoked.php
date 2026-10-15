<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\Guardian;
use App\Models\User;
use App\Services\StudentDataEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsentPurgeRevoked extends Command
{
    protected $signature = 'consent:purge-revoked';

    protected $description = 'Erase child accounts whose parental consent was revoked past the grace period';

    public function handle(StudentDataEraser $eraser): int
    {
        $due = Consent::where('status', Consent::STATUS_REVOKED)
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->get();

        $processed = 0;
        $skipped = 0;

        // Grouped by guardian, not processed one consent at a time: revoke()
        // revokes EVERY currently-CONFIRMED consent for a guardian in one
        // call (its own docblock calls this "belt and braces" for rows that
        // predate the single-governing-consent fix), so a guardian can hold
        // two REVOKED rows sharing the same purge_after and both due here in
        // the same run. Processing them one at a time would delete the
        // guardian after the first row and then throw a foreign-key
        // violation writing the 'purged' audit row for the second, whose
        // consent_id no longer exists once the guardian cascade-deletes it.
        // Grouping handles the guardian's whole due set in one place.
        foreach ($due->groupBy('guardian_id') as $guardianId => $consentsForGuardian) {
            try {
                DB::transaction(function () use ($guardianId, $consentsForGuardian, $eraser) {
                    $this->purgeGuardian((int) $guardianId, $consentsForGuardian, $eraser);
                });

                $processed++;
            } catch (Throwable $e) {
                // One poisoned guardian must degrade to a skip, never abort
                // the whole run — a prior version of this command let an
                // unhandled exception (e.g. an FK violation from a
                // never-cleaned-up related row) propagate out of handle(),
                // which stopped every guardian after it in $due from ever
                // being purged, on every subsequent nightly run, silently.
                // The transaction above already rolled back any partial
                // work for this guardian, so the next run retries it
                // untouched.
                $skipped++;
                report($e);
                Log::error('consent.purge_revoked.guardian_failed', [
                    'guardian_id' => $guardianId,
                    'consent_ids' => $consentsForGuardian->pluck('id')->all(),
                ]);
                $this->error("Skipped guardian {$guardianId} after an error — see logs.");
            }
        }

        $this->info(sprintf(
            'Done. %d consent(s) considered, %d guardian(s) processed%s.',
            $due->count(),
            $processed,
            $skipped ? ", {$skipped} skipped after an error" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Erase (or, on re-consent, merely tidy up) everything due for one
     * guardian. Runs inside the caller's DB::transaction so a failure
     * partway through — e.g. StudentDataEraser::erase() hitting an
     * unexpected RESTRICT constraint — leaves nothing half-deleted; the next
     * run sees the guardian exactly as this one found it.
     */
    private function purgeGuardian(int $guardianId, Collection $consentsForGuardian, StudentDataEraser $eraser): void
    {
        // A parent can revoke and later re-onboard with the same email:
        // requestConsent() reuses the guardian row via firstOrCreate(). The
        // old REVOKED consent keeps whatever purge_after it was given —
        // supersedePendingConsents()/supersedeConfirmedConsents() only ever
        // retire PENDING or CONFIRMED rows, never a REVOKED one — so it can
        // still turn up here as "due" even though the family now has a
        // perfectly valid, currently-governing consent. Erasing on the
        // strength of the stale row alone would delete a family whose
        // consent is live: the child, the new CONFIRMED consent, and the
        // guardian would all be destroyed by mistake.
        //
        // A PENDING consent does not count as governing here — a request
        // that was never confirmed never authorised anyone, so it must not
        // block a genuinely due erasure.
        $hasGoverningConsent = Consent::where('guardian_id', $guardianId)
            ->where('status', Consent::STATUS_CONFIRMED)
            ->exists();

        if ($hasGoverningConsent) {
            foreach ($consentsForGuardian as $consent) {
                // The stale row itself is still removed and still logged —
                // decision 9's audit trail covers this row's lifecycle too —
                // but nothing about the guardian, their children or their
                // classroom is touched.
                ConsentAuditLog::record($consent, 'purged', null, [
                    'children' => 0,
                    'note' => 'guardian re-consented; stale revoked consent removed, no data erased',
                ]);

                ConsentAuditLog::where('consent_id', $consent->id)
                    ->update(['ip' => null, 'user_agent' => null]);

                $consent->delete();
            }

            $this->info(sprintf(
                'Guardian %d has re-consented — removed %d stale consent row(s), erased nothing.',
                $guardianId,
                $consentsForGuardian->count(),
            ));

            return;
        }

        $children = User::where('guardian_id', $guardianId)->get();

        foreach ($children as $child) {
            $eraser->erase($child);
        }

        foreach ($consentsForGuardian as $consent) {
            // Written before the consent row disappears; the log survives
            // it thanks to nullOnDelete, which is what keeps the erasure
            // provable.
            ConsentAuditLog::record($consent, 'purged', null, [
                'children' => $children->count(),
            ]);
        }

        $guardian = Guardian::find($guardianId);

        foreach ($consentsForGuardian as $consent) {
            $consent->delete();
        }

        // The trail is kept forever by design (decision 9), but the
        // guardian's IP and user agent are personal data and must not
        // outlive the erasure they describe. Scoped to guardian_id, not
        // just the consents that were due: deleting the guardian below
        // cascades away every OTHER consent they hold too — e.g. an
        // EXPIRED row that supersedePendingConsents()/
        // supersedeConfirmedConsents() left behind on a lost-code retry,
        // carrying its own real 'requested'/'confirmed' audit events. Once
        // the guardian is gone, those rows' consent_id AND guardian_id both
        // go null (nullOnDelete on both), making them unreachable by any
        // future consent-scoped or guardian-scoped cleanup — permanently.
        // Scrubbing the whole guardian's audit history here is safe only
        // because the guardian and every one of their consents (governing
        // or not) are being destroyed in this same transaction; it must
        // NOT be used in the moot/re-consent branch above, where the
        // guardian survives and their other consents are live and still
        // legally disputable. Scrub while guardian_id is still findable —
        // the delete below nulls it.
        ConsentAuditLog::where('guardian_id', $guardianId)
            ->update(['ip' => null, 'user_agent' => null]);

        // The guardian's self-service classroom must go before the guardian
        // itself: classrooms.guardian_id is restrictOnDelete, so leaving it
        // behind makes the delete below throw for essentially every
        // guardian this command processes.
        Classroom::where('guardian_id', $guardianId)->delete();

        $guardian?->delete();

        $this->info(sprintf(
            'Purged guardian %d (%d consent(s), %d account(s)).',
            $guardianId,
            $consentsForGuardian->count(),
            $children->count(),
        ));
    }
}
