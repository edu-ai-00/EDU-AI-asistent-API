<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "When event X happens and these conditions hold, send notification type Y"
 * (NOTIF-7, NOTIF-9). The admin edits `definition` as JSON:
 *
 *   { "when": {"event": "course.completed"},
 *     "if":   [{"field": "percent", "op": "eq", "value": 50}],
 *     "then": {"type": "course_completed", "target_role": "student",
 *              "channels": ["feed"], "delay_minutes": 0} }
 *
 * A condition on a `user.` field (RecipientCatalog, e.g. `user.xp`) narrows
 * the audience instead of the event: the engine checks it per recipient.
 *
 * event_key / notification_type_id mirror when.event / then.type so the
 * engine can look rules up by index; syncDenormalised() keeps them in step.
 * Validation lives in App\Notifications\RuleDefinitionValidator.
 */
class NotificationRule extends Model
{
    public const SYSTEM_KEY_PREFIX = 'system.';

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_system',
        'is_active',
        'event_key',
        'notification_type_id',
        'definition',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'definition' => 'array',
            'notification_type_id' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function eventKey(): ?string
    {
        $event = $this->definition['when']['event'] ?? null;

        return is_string($event) ? $event : null;
    }

    public function typeKey(): ?string
    {
        $type = $this->definition['then']['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** @return list<array{field: string, op: string, value: mixed}> */
    public function conditions(): array
    {
        $if = $this->definition['if'] ?? [];

        return is_array($if) ? array_values($if) : [];
    }

    /** Requested channels, or null to take the type's. */
    public function channels(): ?array
    {
        $channels = $this->definition['then']['channels'] ?? null;

        return is_array($channels) ? array_values($channels) : null;
    }

    public function targetRole(): ?string
    {
        $role = $this->definition['then']['target_role'] ?? null;

        return is_string($role) ? $role : null;
    }

    public function delayMinutes(): int
    {
        return max(0, (int) ($this->definition['then']['delay_minutes'] ?? 0));
    }

    /**
     * Copy when.event / then.type out of the definition into the indexed
     * columns. Returns false when then.type names no existing type.
     */
    public function syncDenormalised(): bool
    {
        $this->event_key = (string) $this->eventKey();

        $type = NotificationType::where('key', (string) $this->typeKey())->first();
        if ($type === null) {
            return false;
        }

        $this->notification_type_id = $type->id;

        return true;
    }
}
