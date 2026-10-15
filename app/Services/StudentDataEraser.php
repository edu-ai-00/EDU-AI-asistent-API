<?php

namespace App\Services;

use App\Http\Controllers\Api\AnswerPhotoController;
use App\Models\DebugReport;
use App\Models\User;
use App\Models\UserMerge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Irreversible erasure of a child account.
 *
 * Verified against the migrations (BR-8FDF37 Task 10 review), not just
 * assumed: these user-owned tables cascadeOnDelete on user_id (or, for
 * skill_event_logs, student_id) and need no help here — user_courses,
 * user_stats, content_feedback, user_progress, quiz_attempts, bookmarks,
 * user_elo_profiles, elo_interactions, user_achievements, chat_sessions
 * (chat_messages cascades transitively via chat_session_id), user_themes,
 * practice_cards, practice_review_logs (cascades directly on its own
 * user_id, and again transitively via card_id), student_fsrs_profiles,
 * news_user_reads, work_heartbeats, skill_event_logs. courses.created_by is
 * nullOnDelete, not cascade, but is never a minor's own data (minors don't
 * author courses) so it is out of scope here. This list is the prose
 * backstop; StudentDataEraserTest::
 * test_erasing_a_child_cascades_across_every_owned_table() is the version
 * that actually gets checked when a migration changes.
 *
 * Two tables need explicit handling because they do not cascade cleanly:
 *
 * - personal_access_tokens is polymorphic with no foreign key at all, so
 *   nothing would ever remove a child's Sanctum tokens without this.
 * - debug_reports.user_id was declared nullOnDelete() but, until the
 *   migration that added an actual ->constrained() call, carried no real
 *   foreign key — Fluent drops nullOnDelete() silently when there's nothing
 *   to attach it to. Even with the FK now real, nulling user_id would only
 *   anonymise the row, not erase report_data (arbitrary client-submitted
 *   text) — so it is deleted outright here, the same as everything else.
 *
 * user_merges.source_user_id / target_user_id / performed_by are all three
 * restrictOnDelete (that audit trail must outlive a purged user too), which
 * would make this throw if a minor account ever appeared there.
 * UserMergeService::loadAndValidate() now rejects a minor as either side of
 * an admin-initiated merge, and absorbGuest() already can't reach one — a
 * minor has no email/OAuth identity to be resolved as a merge target, and is
 * never created as a guest — so both call paths into user_merges are closed.
 * Belt and braces: consent:purge-revoked wraps each guardian's purge in its
 * own try/catch, so even if this ever throws for a reason neither guard
 * anticipated, one poisoned account degrades to a skipped guardian, not an
 * aborted batch.
 *
 * An ordinary student is a different matter: one who used the app as a
 * guest before signing in is the target of exactly such a row
 * (absorbGuest() records performed_by = target). eraseWithMergeHistory() is
 * for the admin "Smazat" (BR-FXWJ8D), which removes a student and their
 * whole history: the merge rows naming the account go first, and so do the
 * profiles merged into it — finalizeSource() keeps those as hollow rows, and
 * users.merged_into_user_id's nullOnDelete would turn them back into
 * stand-alone accounts once their target is gone. erase() itself keeps the
 * restrict for the consent purge above.
 *
 * One more class of data does not live in the database at all: photographed
 * answers (BR-P745Q8). open_answer_evaluations cascades on user_id like every
 * other owned table, but the objects it points at sit in R2. Deleting the rows
 * would leave photographs of a child's schoolwork in the bucket with nothing
 * left pointing at them — erased on paper, still there in fact.
 */
class StudentDataEraser
{
    public function erase(User $child): void
    {
        DB::transaction(fn () => $this->deleteRows($child));

        // Outside the transaction on purpose. R2 is not transactional, so a
        // rollback could not undo these deletes anyway, and a bucket outage
        // must not roll back an erasure that is legally due. Failing here
        // leaves orphaned objects and a loud log line, not a live account.
        $this->eraseAnswerPhotos($child->id);
    }

    /**
     * erase() for a student an admin deletes (BR-FXWJ8D), together with the
     * merge rows naming them and every profile merged into them, however
     * deep the chain (a guest merged into a guest later merged into them).
     */
    public function eraseWithMergeHistory(User $student): void
    {
        $accounts = collect([$student]);
        $frontier = [$student->id];

        while ($frontier !== []) {
            $merged = User::whereIn('merged_into_user_id', $frontier)
                ->whereNotIn('id', $accounts->pluck('id'))
                ->get();
            $accounts = $accounts->concat($merged);
            $frontier = $merged->pluck('id')->all();
        }

        $ids = $accounts->pluck('id')->all();

        DB::transaction(function () use ($accounts, $ids) {
            UserMerge::whereIn('source_user_id', $ids)
                ->orWhereIn('target_user_id', $ids)
                ->delete();

            $accounts->each(fn (User $account) => $this->deleteRows($account));
        });

        // Outside the transaction, as in erase().
        foreach ($ids as $id) {
            $this->eraseAnswerPhotos($id);
        }
    }

    private function deleteRows(User $user): void
    {
        $user->tokens()->delete();
        DebugReport::where('user_id', $user->id)->delete();
        $user->delete();
    }

    /**
     * Delete every photographed answer the child uploaded.
     *
     * Deletes the whole per-user prefix rather than walking the rows, because
     * the rows are already gone by this point — and because an upload that was
     * never attached to an attempt (student picked a photo, then closed the
     * app) has no row to walk in the first place.
     */
    private function eraseAnswerPhotos(int $userId): void
    {
        $prefix = AnswerPhotoController::PREFIX . '/' . $userId;

        try {
            Storage::disk('s3')->deleteDirectory($prefix);
        } catch (\Throwable $e) {
            Log::error('student_erasure.answer_photos_failed', [
                'user_id' => $userId,
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
