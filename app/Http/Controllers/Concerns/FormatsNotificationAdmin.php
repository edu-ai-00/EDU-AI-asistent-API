<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AuditLog;
use App\Models\NotificationBroadcast;
use App\Models\NotificationEvent;
use App\Models\NotificationRule;
use App\Models\NotificationType;
use App\Models\UserNotification;
use App\Notifications\NotificationResult;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * JSON shapes of the notification admin (spec §5.2), shared by its
 * controllers so a type, rule or broadcast reads the same from every
 * endpoint that returns one.
 */
trait FormatsNotificationAdmin
{
    /** @var array<string, string>|null  type key => name, read once per request */
    private ?array $notificationTypeNames = null;

    /** @return array<string, mixed> */
    protected function typeJson(NotificationType $type): array
    {
        $json = [
            'id' => $type->id,
            'key' => $type->key,
            'name' => $type->name,
            'description' => $type->description,
            'category' => $type->category,
            'target_role' => $type->target_role,
            'is_active' => (bool) $type->is_active,
            'is_mandatory' => (bool) $type->is_mandatory,
            'is_system' => (bool) $type->is_system,
            'manual_allowed' => (bool) $type->manual_allowed,
            'channels' => $type->channelList(),
            'respect_quiet_hours' => (bool) $type->respect_quiet_hours,
            'frequency_limit' => $type->frequency_limit,
            'frequency_period_hours' => $type->frequency_period_hours,
            'dedup_window_hours' => (int) $type->dedup_window_hours,
            'push_title' => $type->push_title,
            'push_body' => $type->push_body,
            'feed_title' => $type->feed_title,
            'feed_body' => $type->feed_body,
            'variables' => array_values((array) $type->variables),
            'deep_link' => $type->deep_link,
            'target_action' => $type->target_action,
            'created_at' => $type->created_at?->toIso8601String(),
            'updated_at' => $type->updated_at?->toIso8601String(),
        ];

        if (array_key_exists('rules_count', $type->getAttributes())) {
            $json['rules_count'] = (int) $type->rules_count;
        }

        if (array_key_exists('last_sent_at', $type->getAttributes())) {
            $json['last_sent_at'] = $this->isoOrNull($type->getAttributes()['last_sent_at']);
        }

        return $json;
    }

    /** @return array<string, mixed> */
    protected function ruleJson(NotificationRule $rule): array
    {
        $type = $rule->relationLoaded('type') ? $rule->type : $rule->type()->first();

        return [
            'id' => $rule->id,
            'key' => $rule->key,
            'name' => $rule->name,
            'description' => $rule->description,
            'is_system' => (bool) $rule->is_system,
            'is_active' => (bool) $rule->is_active,
            'event_key' => $rule->event_key,
            'notification_type_id' => $rule->notification_type_id,
            'definition' => $rule->definition,
            'created_by' => $rule->created_by,
            'updated_by' => $rule->updated_by,
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
            'type' => $type ? ['id' => $type->id, 'key' => $type->key, 'name' => $type->name] : null,
        ];
    }

    /**
     * `delivered_count` is the recipients the broadcast reached on their
     * device (NotificationBroadcast::deliveredCounts), `read_count` those
     * who have seen it (NotificationBroadcast::readCounts); a list passes the
     * counts it read in one query each.
     *
     * @return array<string, mixed>
     */
    protected function broadcastJson(NotificationBroadcast $broadcast, ?int $deliveredCount = null, ?int $readCount = null): array
    {
        $deliveredCount ??= NotificationBroadcast::deliveredCounts([$broadcast])[$broadcast->id] ?? 0;
        $readCount ??= NotificationBroadcast::readCounts([$broadcast])[$broadcast->id] ?? 0;

        return [
            'id' => $broadcast->id,
            'title' => $broadcast->title,
            'body' => $broadcast->body,
            'type_key' => $broadcast->type_key,
            'type_name' => ($this->notificationTypeNames ??= NotificationType::pluck('name', 'key')->all())[$broadcast->type_key] ?? null,
            'channels' => array_values((array) $broadcast->channels),
            'targeting' => [
                'school_ids' => array_values(array_map('intval', (array) ($broadcast->targeting['school_ids'] ?? []))),
                'classroom_ids' => array_values(array_map('intval', (array) ($broadcast->targeting['classroom_ids'] ?? []))),
                'roles' => array_values((array) ($broadcast->targeting['roles'] ?? [])),
            ],
            'targeting_snapshot' => $broadcast->targeting_snapshot,
            'deadline_at' => $broadcast->deadline_at?->toIso8601String(),
            'status' => $broadcast->status,
            'recipient_count' => (int) $broadcast->recipient_count,
            'processed_count' => (int) $broadcast->processed_count,
            'delivered_count' => $deliveredCount,
            'suppressed_count' => (int) $broadcast->suppressed_count,
            'failed_count' => (int) $broadcast->failed_count,
            'read_count' => $readCount,
            'created_by' => $broadcast->created_by,
            'updated_by' => $broadcast->updated_by,
            'sent_by' => $broadcast->sent_by,
            'sent_at' => $broadcast->sent_at?->toIso8601String(),
            'completed_at' => $broadcast->completed_at?->toIso8601String(),
            'archived_at' => $broadcast->archived_at?->toIso8601String(),
            'archived_by' => $broadcast->archived_by,
            'deadline_reminded_at' => $broadcast->deadline_reminded_at?->toIso8601String(),
            'created_at' => $broadcast->created_at?->toIso8601String(),
            'updated_at' => $broadcast->updated_at?->toIso8601String(),
        ];
    }

    /**
     * GET …/{id}/audit — the audit log rows about one target, newest first,
     * in the shape of GET /api/admin/audit-logs.
     */
    protected function auditFor(string $targetType, int $targetId): JsonResponse
    {
        $rows = AuditLog::where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'actor_id' => $log->actor_id,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                ] : null,
                'actor_role' => $log->actor_role,
                'action' => $log->action,
                'outcome' => $log->outcome,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'scope' => [
                    'school_id' => $log->school_id,
                    'classroom_id' => $log->classroom_id,
                ],
                'source' => $log->source,
                'diff' => $log->diff,
                'meta' => $log->meta,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
            ])->values(),
            'meta' => ['total' => $rows->count()],
        ]);
    }

    /**
     * A send's per-recipient decisions for the admin test panels: each with
     * its notification_events name (`decision.deliver` …) as `event`, plus a
     * row for the push sub-decision when there was one.
     *
     * @return list<array<string, mixed>>
     */
    protected function decisionRows(NotificationResult $result): array
    {
        $rows = [];

        foreach ($result->decisions as $decision) {
            $rows[] = $decision + ['event' => 'decision.' . $decision['decision']];

            $push = $decision['push'] ?? null;
            if ($push === null) {
                continue;
            }

            $rows[] = [
                'user_id' => $decision['user_id'],
                'decision' => 'push',
                'event' => match ($push['status']) {
                    UserNotification::PUSH_SKIPPED => NotificationEvent::DECISION_SUPPRESS,
                    UserNotification::PUSH_DEFERRED => NotificationEvent::DECISION_DEFER,
                    default => $push['status'],
                },
                'reason' => $push['reason'],
                'notification_id' => $decision['notification_id'],
                'meta' => ['channel' => 'push', 'status' => $push['status']],
            ];
        }

        return $rows;
    }

    protected function isoOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : Carbon::parse((string) $value, 'UTC')->toIso8601String();
    }
}
