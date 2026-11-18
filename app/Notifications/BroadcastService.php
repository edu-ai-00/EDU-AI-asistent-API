<?php

namespace App\Notifications;

use App\Jobs\ProcessBroadcastChunk;
use App\Models\Classroom;
use App\Models\NotificationBroadcast;
use App\Models\NotificationBroadcastRecipient;
use App\Models\NotificationRule;
use App\Models\NotificationType;
use App\Models\School;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Manual notifications to schools and classes (NOTIF-10, spec §5.2, §5.3).
 *
 * Recipients (§5.3):
 *   - school_ids non-empty; classroom_ids empty = every class of those
 *     schools, otherwise only the picked classes (which must belong to the
 *     picked schools)
 *   - students: members (classroom_student) of the classes
 *   - teachers: teachers (classroom_teacher) of the classes, plus the
 *     schools' own teachers (school_teacher) when no class was picked
 *   - deactivated and merged accounts are skipped; a user reached through
 *     several classes / groups is addressed once (NOTIF-10 F)
 *
 * Sending recomputes the recipients (permissions may have changed since the
 * estimate), freezes them as notification_broadcast_recipients plus a
 * targeting_snapshot of the names, and hands the snapshot in chunks to
 * ProcessBroadcastChunk, which runs `broadcast.sent` through the rule engine
 * — the same pipeline as every other notification.
 */
class BroadcastService
{
    public const ROLES = ['student', 'teacher'];

    public function __construct(private readonly Dispatcher $bus) {}

    // ═══════════════════════════════════════════════════════════════════════════
    // Targeting & recipients
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Validation rules for a `targeting` object under $prefix.
     *
     * @return array<string, mixed>
     */
    public static function targetingRules(string $prefix = 'targeting'): array
    {
        return [
            $prefix => 'required|array',
            $prefix . '.school_ids' => 'required|array|min:1',
            $prefix . '.school_ids.*' => 'integer|distinct|exists:schools,id',
            $prefix . '.classroom_ids' => 'sometimes|array',
            $prefix . '.classroom_ids.*' => 'integer|distinct|exists:classrooms,id',
            $prefix . '.roles' => 'required|array|min:1',
            $prefix . '.roles.*' => 'string|distinct|in:' . implode(',', self::ROLES),
        ];
    }

    /**
     * Canonical targeting (sorted int ids, roles in fixed order) after the
     * check Laravel rules cannot express: picked classes belong to the
     * picked schools.
     *
     * @param  array<string, mixed>  $targeting  already through targetingRules()
     * @return array{school_ids: list<int>, classroom_ids: list<int>, roles: list<string>}
     *
     * @throws ValidationException
     */
    public function normalizeTargeting(array $targeting, string $prefix = 'targeting'): array
    {
        $schoolIds = self::ids($targeting['school_ids'] ?? []);
        $classroomIds = self::ids($targeting['classroom_ids'] ?? []);
        $roles = array_values(array_intersect(self::ROLES, (array) ($targeting['roles'] ?? [])));

        if ($classroomIds !== []) {
            $foreign = Classroom::whereIn('id', $classroomIds)
                ->where(function ($q) use ($schoolIds) {
                    $q->whereNull('school_id')->orWhereNotIn('school_id', $schoolIds);
                })
                ->pluck('name')
                ->all();

            if ($foreign !== []) {
                throw ValidationException::withMessages([
                    $prefix . '.classroom_ids' => [sprintf(
                        'Třídy %s nepatří k žádné z vybraných škol.',
                        implode(', ', $foreign),
                    )],
                ]);
            }
        }

        return ['school_ids' => $schoolIds, 'classroom_ids' => $classroomIds, 'roles' => $roles];
    }

    /**
     * Resolve the recipients of a targeting.
     *
     * @param  array{school_ids: list<int>, classroom_ids: list<int>, roles: list<string>}  $targeting
     * @return array{recipients: array<int, array{user_id: int, role: string, school_id: ?int, classroom_id: ?int}>,
     *               skipped: int, classrooms: \Illuminate\Support\Collection}
     */
    public function resolve(array $targeting): array
    {
        $classrooms = Classroom::query()
            ->whereIn('school_id', $targeting['school_ids'])
            ->when($targeting['classroom_ids'] !== [], fn ($q) => $q->whereIn('id', $targeting['classroom_ids']))
            ->orderBy('id')
            ->get(['id', 'name', 'school_id']);

        $classIds = $classrooms->pluck('id')->all();
        $schoolOf = $classrooms->pluck('school_id', 'id')->all();

        $rows = collect();

        if (in_array('student', $targeting['roles'], true) && $classIds !== []) {
            $rows = $rows->concat(
                DB::table('classroom_student')
                    ->join('users', 'users.id', '=', 'classroom_student.user_id')
                    ->whereIn('classroom_student.classroom_id', $classIds)
                    ->where('users.role', 'student')
                    ->orderBy('classroom_student.classroom_id')
                    ->orderBy('users.id')
                    ->get([
                        'users.id as user_id',
                        'classroom_student.classroom_id as classroom_id',
                        'users.deactivated_at',
                        'users.merged_into_user_id',
                    ])
                    ->map(fn ($r) => ['role' => 'student', 'school_id' => $schoolOf[$r->classroom_id] ?? null] + (array) $r),
            );
        }

        if (in_array('teacher', $targeting['roles'], true)) {
            if ($classIds !== []) {
                $rows = $rows->concat(
                    DB::table('classroom_teacher')
                        ->join('users', 'users.id', '=', 'classroom_teacher.teacher_id')
                        ->whereIn('classroom_teacher.classroom_id', $classIds)
                        ->whereIn('users.role', ['teacher', 'admin'])
                        ->orderBy('classroom_teacher.classroom_id')
                        ->orderBy('users.id')
                        ->get([
                            'users.id as user_id',
                            'classroom_teacher.classroom_id as classroom_id',
                            'users.deactivated_at',
                            'users.merged_into_user_id',
                        ])
                        ->map(fn ($r) => ['role' => 'teacher', 'school_id' => $schoolOf[$r->classroom_id] ?? null] + (array) $r),
                );
            }

            // Whole schools: their teachers count even without a class.
            if ($targeting['classroom_ids'] === []) {
                $rows = $rows->concat(
                    DB::table('school_teacher')
                        ->join('users', 'users.id', '=', 'school_teacher.teacher_id')
                        ->whereIn('school_teacher.school_id', $targeting['school_ids'])
                        ->whereIn('users.role', ['teacher', 'admin'])
                        ->orderBy('school_teacher.school_id')
                        ->orderBy('users.id')
                        ->get([
                            'users.id as user_id',
                            'school_teacher.school_id as school_id',
                            'users.deactivated_at',
                            'users.merged_into_user_id',
                        ])
                        ->map(fn ($r) => ['role' => 'teacher', 'classroom_id' => null] + (array) $r),
                );
            }
        }

        $recipients = [];
        $skipped = [];

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];

            if ($row['deactivated_at'] !== null || $row['merged_into_user_id'] !== null) {
                $skipped[$userId] = true;

                continue;
            }

            // First group wins — deduplicated across classes, schools and roles.
            $recipients[$userId] ??= [
                'user_id' => $userId,
                'role' => $row['role'],
                'school_id' => $row['school_id'] !== null ? (int) $row['school_id'] : null,
                'classroom_id' => $row['classroom_id'] !== null ? (int) $row['classroom_id'] : null,
            ];
        }

        return [
            'recipients' => $recipients,
            'skipped' => count(array_diff_key($skipped, $recipients)),
            'classrooms' => $classrooms,
        ];
    }

    /**
     * §5.3: wide = at least `broadcast.wide_threshold` recipients, or whole
     * schools (no class picked).
     *
     * @param  array{classroom_ids: list<int>}  $targeting
     */
    public static function isWide(array $targeting, int $recipientCount): bool
    {
        return $targeting['classroom_ids'] === []
            || $recipientCount >= (int) config('notifications.broadcast.wide_threshold', 200);
    }

    /**
     * POST …/estimate — works for unsaved forms.
     *
     * @param  array{school_ids: list<int>, classroom_ids: list<int>, roles: list<string>}  $targeting
     * @return array{recipient_count: int, by_role: array{student: int, teacher: int}, wide: bool, warnings: list<array{code: string, message: string}>}
     */
    public function estimate(array $targeting): array
    {
        $resolved = $this->resolve($targeting);
        $count = count($resolved['recipients']);

        $byRole = ['student' => 0, 'teacher' => 0];
        foreach ($resolved['recipients'] as $recipient) {
            $byRole[$recipient['role']]++;
        }

        return [
            'recipient_count' => $count,
            'by_role' => $byRole,
            'wide' => self::isWide($targeting, $count),
            'warnings' => $this->targetingWarnings($targeting, $resolved),
        ];
    }

    /**
     * @param  array{school_ids: list<int>, classroom_ids: list<int>, roles: list<string>}  $targeting
     * @param  array{recipients: array, skipped: int, classrooms: \Illuminate\Support\Collection}  $resolved
     * @return list<array{code: string, message: string}>
     */
    private function targetingWarnings(array $targeting, array $resolved): array
    {
        $warnings = [];

        if ($targeting['classroom_ids'] !== []) {
            $withClasses = $resolved['classrooms']->pluck('school_id')->unique()->all();
            $dropped = array_values(array_diff($targeting['school_ids'], $withClasses));

            if ($dropped !== []) {
                $names = School::whereIn('id', $dropped)->orderBy('name')->pluck('name')->all();
                $warnings[] = [
                    'code' => 'schools_without_classes',
                    'message' => sprintf(
                        'Ve školách %s není vybraná žádná třída, rozesílka je nezasáhne.',
                        implode(', ', $names),
                    ),
                ];
            }
        }

        if ($resolved['skipped'] > 0) {
            $warnings[] = [
                'code' => 'inactive_skipped',
                'message' => sprintf(
                    'Vynecháno %d deaktivovaných nebo sloučených účtů.',
                    $resolved['skipped'],
                ),
            ];
        }

        if ($resolved['recipients'] === []) {
            $warnings[] = [
                'code' => 'no_recipients',
                'message' => 'Výběru neodpovídá žádný příjemce.',
            ];
        }

        return $warnings;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Type & rule checks
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Why this broadcast cannot go out as it is — the type is not manual,
     * inactive, has no active broadcast rule, or none of the chosen channels
     * survive. field => message; empty when it can be sent.
     *
     * @return array<string, string>
     */
    public function sendBlockers(NotificationBroadcast $broadcast): array
    {
        $type = $broadcast->type();

        if ($type === null || !$type->manual_allowed) {
            return ['type_key' => sprintf('Typ „%s“ nelze použít pro ruční rozesílku.', $broadcast->type_key)];
        }

        if (!$type->is_active) {
            return ['type_key' => sprintf('Typ „%s“ je vypnutý.', $type->name)];
        }

        $rules = $this->matchingRules($broadcast, $type);
        if ($rules === []) {
            return ['type_key' => sprintf(
                'Typ „%s“ nemá aktivní pravidlo pro rozesílky (událost broadcast.sent). Zapněte ho v záložce Pravidla.',
                $type->name,
            )];
        }

        $channels = array_values(array_intersect($type->channelList(), (array) $broadcast->channels));
        if ($channels === []) {
            return ['channels' => sprintf('Typ „%s“ nepovoluje žádný z vybraných kanálů.', $type->name)];
        }

        $reachable = false;
        foreach ($rules as $rule) {
            $ruleChannels = $rule->channels() ?? $type->channelList();
            if (array_intersect($ruleChannels, $channels) !== []) {
                $reachable = true;

                break;
            }
        }

        if (!$reachable) {
            return ['channels' => 'Pravidlo rozesílky tohoto typu neposílá žádný z vybraných kanálů.'];
        }

        return [];
    }

    /**
     * Active broadcast.sent rules that would send this broadcast's type.
     *
     * @return list<NotificationRule>
     */
    public function matchingRules(NotificationBroadcast $broadcast, NotificationType $type): array
    {
        $engine = app(RuleEngine::class);
        $fields = $this->eventFields($broadcast);

        return NotificationRule::where('event_key', 'broadcast.sent')
            ->where('is_active', true)
            ->where('notification_type_id', $type->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (NotificationRule $rule) => $engine->evaluateConditions('broadcast.sent', $rule->conditions(), $fields)['matched'])
            ->values()
            ->all();
    }

    /** @return array{type_key: string, broadcast_id: int, title: string, body: string} */
    public function eventFields(NotificationBroadcast $broadcast): array
    {
        return [
            'type_key' => (string) $broadcast->type_key,
            'broadcast_id' => (int) $broadcast->id,
            'title' => (string) $broadcast->title,
            'body' => (string) $broadcast->body,
        ];
    }

    /**
     * POST …/{id}/preview — recipients and the texts as the type renders them.
     *
     * @return array{recipient_count: int, wide: bool, rendered: array<string, ?string>, warnings: list<array{code: string, message: string}>}
     */
    public function preview(NotificationBroadcast $broadcast): array
    {
        $targeting = $this->normalizeTargeting((array) $broadcast->targeting);
        $resolved = $this->resolve($targeting);
        $count = count($resolved['recipients']);
        $warnings = $this->targetingWarnings($targeting, $resolved);

        foreach ($this->sendBlockers($broadcast) as $code => $message) {
            $warnings[] = ['code' => 'blocked_' . $code, 'message' => $message];
        }

        $type = $broadcast->type();
        $rendered = ['push_title' => null, 'push_body' => null, 'feed_title' => null, 'feed_body' => null, 'deep_link' => null];

        if ($type !== null) {
            $payload = RuleEngine::payloadFor('broadcast.sent', $this->eventFields($broadcast));
            $full = app(TemplateRenderer::class)->renderAll($type, $payload);
            $limits = TemplateRenderer::limits();
            $withPush = in_array(NotificationType::CHANNEL_PUSH, (array) $broadcast->channels, true)
                && in_array(NotificationType::CHANNEL_PUSH, $type->channelList(), true);

            foreach (['push_title', 'push_body', 'feed_title', 'feed_body'] as $key) {
                if (str_starts_with($key, 'push_') && !$withPush) {
                    continue;
                }

                $rendered[$key] = TemplateRenderer::truncate($full[$key], $limits[$key]);

                if (mb_strlen($full[$key]) > $limits[$key]) {
                    $warnings[] = [
                        'code' => 'truncated_' . $key,
                        'message' => sprintf(
                            '%s má %d znaků, zobrazí se zkrácený na %d.',
                            str_starts_with($key, 'push_') ? 'Text pushe' : 'Text v Novinkách',
                            mb_strlen($full[$key]),
                            $limits[$key],
                        ),
                    ];
                }
            }

            $rendered['deep_link'] = $full['deep_link'];
        }

        return [
            'recipient_count' => $count,
            'wide' => self::isWide($targeting, $count),
            'rendered' => $rendered,
            'warnings' => $warnings,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Send
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Freeze the recipients and start delivery. Returns the broadcast in
     * `sending` (or already `sent` when the queue runs synchronously).
     *
     * @throws ValidationException  draft only, type/rule/channels, empty, wide unconfirmed
     */
    public function send(NotificationBroadcast $broadcast, bool $confirmWide, ?int $actorId): NotificationBroadcast
    {
        if (!$broadcast->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Rozesílku lze odeslat jen jako koncept; tato už byla odeslána nebo archivována.'],
            ]);
        }

        $blockers = $this->sendBlockers($broadcast);
        if ($blockers !== []) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $blockers));
        }

        // Recomputed now: a student may have left the class since the estimate.
        $targeting = $this->normalizeTargeting((array) $broadcast->targeting);
        $resolved = $this->resolve($targeting);
        $recipients = array_values($resolved['recipients']);

        if ($recipients === []) {
            throw ValidationException::withMessages([
                'targeting' => ['Výběru neodpovídá žádný příjemce, rozesílku nelze odeslat.'],
            ]);
        }

        $wide = self::isWide($targeting, count($recipients));
        if ($wide && !$confirmWide) {
            throw ValidationException::withMessages([
                'confirm_wide' => [sprintf(
                    'Rozesílka zasáhne %d příjemců%s. Potvrďte prosím široké odeslání.',
                    count($recipients),
                    $targeting['classroom_ids'] === [] ? ' v celých školách' : '',
                )],
            ]);
        }

        $snapshot = $this->targetingSnapshot($targeting, $resolved);

        DB::transaction(function () use ($broadcast, $recipients, $snapshot, $actorId) {
            // The claim: only one send can move a draft to sending.
            $claimed = NotificationBroadcast::whereKey($broadcast->id)
                ->where('status', NotificationBroadcast::STATUS_DRAFT)
                ->update([
                    'status' => NotificationBroadcast::STATUS_SENDING,
                    'recipient_count' => count($recipients),
                    'processed_count' => 0,
                    'delivered_count' => 0,
                    'suppressed_count' => 0,
                    'failed_count' => 0,
                    'targeting_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    'sent_by' => $actorId,
                    'updated_by' => $actorId,
                    'sent_at' => now(),
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'status' => ['Rozesílku mezitím odeslal někdo jiný.'],
                ]);
            }

            NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)->delete();

            foreach (array_chunk($recipients, 1000) as $rows) {
                NotificationBroadcastRecipient::insert(array_map(fn (array $r) => $r + ['broadcast_id' => $broadcast->id], $rows));
            }
        });

        // After the commit: the chunk jobs must see the snapshot, and a
        // synchronous queue must not run sends inside the transaction.
        $this->dispatchChunks($broadcast->fresh());

        return $broadcast->fresh();
    }

    /**
     * @param  array{school_ids: list<int>, classroom_ids: list<int>, roles: list<string>}  $targeting
     * @return array<string, mixed>
     */
    private function targetingSnapshot(array $targeting, array $resolved): array
    {
        $byRole = ['student' => 0, 'teacher' => 0];
        foreach ($resolved['recipients'] as $recipient) {
            $byRole[$recipient['role']]++;
        }

        return [
            'schools' => School::whereIn('id', $targeting['school_ids'])->orderBy('name')->get(['id', 'name'])
                ->map(fn (School $s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'classrooms' => $resolved['classrooms']
                ->map(fn (Classroom $c) => ['id' => $c->id, 'name' => $c->name, 'school_id' => $c->school_id])->values()->all(),
            'all_classrooms' => $targeting['classroom_ids'] === [],
            'roles' => $targeting['roles'],
            'recipient_count' => count($resolved['recipients']),
            'by_role' => $byRole,
            'skipped_inactive' => $resolved['skipped'],
        ];
    }

    /**
     * One ProcessBroadcastChunk per `broadcast.chunk` recipients. If the very
     * first dispatch fails the queue is down: the send is rolled back to a
     * draft and the admin gets a 503 to retry. A failure later on counts the
     * undispatched recipients as failed so the broadcast still completes.
     */
    private function dispatchChunks(NotificationBroadcast $broadcast): void
    {
        $size = max(1, (int) config('notifications.broadcast.chunk', 500));
        $userIds = NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $chunks = array_chunk($userIds, $size);

        foreach ($chunks as $index => $chunk) {
            try {
                $this->bus->dispatch(new ProcessBroadcastChunk($broadcast->id, $chunk, $index));
            } catch (\Throwable $e) {
                Log::channel('notifications')->error('notifications.broadcast_dispatch_failed', [
                    'broadcast_id' => $broadcast->id,
                    'chunk' => $index,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);

                if ($index === 0) {
                    $this->revertToDraft($broadcast);

                    throw new HttpResponseException(response()->json([
                        'message' => 'Fronta pro odesílání notifikací je teď nedostupná. Rozesílka zůstala jako koncept, zkuste to prosím za chvíli znovu.',
                    ], 503));
                }

                $remaining = array_sum(array_map('count', array_slice($chunks, $index)));
                ProcessBroadcastChunk::count($broadcast->id, 0, 0, $remaining);

                return;
            }
        }
    }

    private function revertToDraft(NotificationBroadcast $broadcast): void
    {
        DB::transaction(function () use ($broadcast) {
            NotificationBroadcastRecipient::where('broadcast_id', $broadcast->id)->delete();
            NotificationBroadcast::whereKey($broadcast->id)->update([
                'status' => NotificationBroadcast::STATUS_DRAFT,
                'recipient_count' => 0,
                'processed_count' => 0,
                'delivered_count' => 0,
                'suppressed_count' => 0,
                'failed_count' => 0,
                'targeting_snapshot' => null,
                'sent_by' => null,
                'sent_at' => null,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $ids = array_values(array_unique(array_map('intval', $values)));
        sort($ids);

        return $ids;
    }
}
