<?php

namespace App\Notifications;

use App\Models\NotificationRule;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns one domain event into notifications (NOTIF-7, spec §3.1).
 *
 * For each active rule listening to the event whose conditions hold, one
 * NotificationService::send() with every recipient of the event:
 *   - source rule, ruleId, the rule's channels and target role
 *   - payload = event fields + derived variables (period_label); `name` is
 *     filled per recipient by the service when the type declares it
 *   - dedupKey = "{type_key}:{event dedup key}"
 *   - deliverAt = now + then.delay_minutes, when set
 *   - source `broadcast` (with broadcast_id) for the broadcast.* events, so
 *     the history can tell a broadcast's notifications from automatic ones
 *
 * $channels optionally narrows every rule's channels further — a broadcast
 * sent as feed-only passes ['feed'] so its system rule cannot add push.
 *
 * A rule's conditions come in two kinds (RecipientCatalog): the event's own
 * fields decide whether the rule runs at all, while `user.*` conditions are
 * checked per recipient and narrow who of the event's recipients the
 * notification goes to. Recipients the latter exclude are reported as
 * `skipped` — they were deliberately left out, not lost.
 *
 * A broken rule (its type gone, a field renamed under it) is logged and
 * skipped; it never stops the other rules for the same event.
 */
class RuleEngine
{
    public function __construct(private readonly NotificationService $service) {}

    /**
     * @param  array<string, mixed>  $fields  already validated against EventCatalog
     * @param  array<int, int>  $userIds
     * @param  string|null  $dedupKey  the event's dedup key; null = derived from the catalog
     * @param  list<string>|null  $channels  narrows every rule's channels (broadcast channel choice)
     * @return list<array{rule_id: int, matched: bool, result: ?NotificationResult, error: ?string, skipped: list<int>}>
     */
    public function evaluate(string $eventKey, array $fields, array $userIds, ?string $dedupKey = null, ?array $channels = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $dedupKey ??= EventCatalog::dedupKey($eventKey, $fields);

        $rules = NotificationRule::with('type')
            ->where('event_key', $eventKey)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $outcomes = [];

        foreach ($rules as $rule) {
            $outcomes[] = $this->runRule($rule, $eventKey, $fields, $userIds, $dedupKey, $channels);
        }

        return $outcomes;
    }

    /**
     * Evaluate one rule's conditions on the event itself, with the detail
     * the admin rule tester shows. The rule's `user.*` conditions are not
     * part of this: they are about the recipient, not the event, and a
     * caller asking "would this rule run?" must not be told no because the
     * event carries no XP.
     *
     * @param  array<int, mixed>  $conditions
     * @param  array<string, mixed>  $fields
     * @return array{matched: bool, conditions: list<array{field: string, op: string, value: mixed, actual: mixed, passed: bool}>}
     */
    public function evaluateConditions(string $eventKey, array $conditions, array $fields): array
    {
        [$eventConditions] = RecipientCatalog::splitConditions($conditions);

        return self::applyConditions(EventCatalog::fields($eventKey), $eventConditions, $fields);
    }

    /**
     * Evaluate a rule's `user.*` conditions for one recipient — what the
     * admin rule tester shows for the user it was given.
     *
     * @param  array<int, mixed>  $conditions  the recipient conditions alone
     * @return array{matched: bool, conditions: list<array{field: string, op: string, value: mixed, actual: mixed, passed: bool}>}
     */
    public function evaluateRecipientConditions(array $conditions, int $userId): array
    {
        $values = RecipientCatalog::valuesFor([$userId])[$userId] ?? [];

        return self::applyConditions(RecipientCatalog::fields(), $conditions, $values);
    }

    /**
     * The template variables an event hands to its notifications.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, scalar|null>
     */
    public static function payloadFor(string $eventKey, array $fields): array
    {
        $payload = [];

        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $payload[(string) $key] = $value;
            }
        }

        foreach (EventCatalog::derivedVariables($eventKey, $fields) as $key => $value) {
            $payload[$key] ??= $value;
        }

        return $payload;
    }

    /**
     * Build the request a rule sends for an event — shared with the admin
     * rule tester so a test send is exactly what the rule would do.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, int>  $userIds
     * @param  list<string>|null  $channels  further narrowing of the rule's channels
     * @param  string|null  $source  null = rule, or broadcast for the broadcast.* events
     */
    public function requestFor(
        NotificationRule $rule,
        string $eventKey,
        array $fields,
        array $userIds,
        string $dedupKey,
        ?string $source = null,
        ?array $channels = null,
    ): NotificationRequest {
        $typeKey = (string) ($rule->type?->key ?? $rule->typeKey());
        $delay = $rule->delayMinutes();
        $broadcastId = isset($fields['broadcast_id']) && str_starts_with($eventKey, 'broadcast.')
            ? (int) $fields['broadcast_id']
            : null;
        $source ??= $broadcastId !== null ? UserNotification::SOURCE_BROADCAST : UserNotification::SOURCE_RULE;

        $ruleChannels = $rule->channels();
        if ($channels !== null) {
            $base = $ruleChannels ?? $rule->type?->channelList() ?? [];
            $ruleChannels = array_values(array_intersect($base, $channels));
        }

        return new NotificationRequest(
            typeKey: $typeKey,
            recipientIds: array_values($userIds),
            payload: self::payloadFor($eventKey, $fields),
            channels: $ruleChannels,
            dedupKey: $typeKey . ':' . $dedupKey,
            source: $source,
            ruleId: $rule->id,
            broadcastId: $broadcastId,
            context: null,
            deliverAt: $delay > 0 && $source !== UserNotification::SOURCE_TEST ? now()->addMinutes($delay) : null,
            targetRole: $rule->targetRole(),
        );
    }

    /**
     * One condition list against one set of values, with the per-condition
     * detail the rule tester shows.
     *
     * @param  array<string, array{type: string}>  $catalog
     * @param  array<int, mixed>  $conditions
     * @param  array<string, mixed>  $values
     * @return array{matched: bool, conditions: list<array{field: string, op: string, value: mixed, actual: mixed, passed: bool}>}
     */
    private static function applyConditions(array $catalog, array $conditions, array $values): array
    {
        $details = [];
        $matched = true;

        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? '');
            $op = (string) ($condition['op'] ?? '');
            $value = $condition['value'] ?? null;
            $actual = $values[$field] ?? null;

            $passed = self::compare($catalog[$field]['type'] ?? EventCatalog::TYPE_STRING, $op, $actual, $value);
            $matched = $matched && $passed;

            $details[] = [
                'field' => $field,
                'op' => $op,
                'value' => $value,
                'actual' => $actual,
                'passed' => $passed,
            ];
        }

        return ['matched' => $matched, 'conditions' => $details];
    }

    /**
     * The recipients a rule's `user.*` conditions let through.
     *
     * @param  array<int, mixed>  $conditions  the recipient conditions alone
     * @param  array<int, int>  $userIds
     * @return array{0: list<int>, 1: list<int>}  [allowed, skipped]
     */
    private static function filterRecipients(array $conditions, array $userIds): array
    {
        $catalog = RecipientCatalog::fields();
        $values = RecipientCatalog::valuesFor($userIds);
        $allowed = [];
        $skipped = [];

        foreach ($userIds as $userId) {
            if (self::applyConditions($catalog, $conditions, $values[$userId] ?? [])['matched']) {
                $allowed[] = $userId;
            } else {
                $skipped[] = $userId;
            }
        }

        return [$allowed, $skipped];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, int>  $userIds
     * @param  list<string>|null  $channels
     * @return array{rule_id: int, matched: bool, result: ?NotificationResult, error: ?string, skipped: list<int>}
     */
    private function runRule(NotificationRule $rule, string $eventKey, array $fields, array $userIds, string $dedupKey, ?array $channels): array
    {
        // Definitions are validated on every save; at run time only what a
        // later edit elsewhere can break is checked. A type that lost one of
        // the rule's channels still sends on the rest (the service
        // intersects, and logs no_channels if nothing is left) rather than
        // silently switching the whole rule off.
        if ($rule->type === null || $rule->eventKey() !== $eventKey) {
            Log::channel('notifications')->warning('notifications.rule_invalid_skipped', [
                'rule_id' => $rule->id,
                'event' => $eventKey,
                'definition_event' => $rule->eventKey(),
                'type_key' => $rule->typeKey(),
            ]);

            return ['rule_id' => $rule->id, 'matched' => false, 'result' => null, 'error' => 'invalid_rule', 'skipped' => []];
        }

        [$eventConditions, $recipientConditions] = RecipientCatalog::splitConditions($rule->conditions());

        $evaluation = self::applyConditions(EventCatalog::fields($eventKey), $eventConditions, $fields);
        if (!$evaluation['matched']) {
            return ['rule_id' => $rule->id, 'matched' => false, 'result' => null, 'error' => null, 'skipped' => []];
        }

        $skipped = [];
        if ($recipientConditions !== []) {
            [$userIds, $skipped] = self::filterRecipients($recipientConditions, $userIds);

            if ($userIds === []) {
                // The event happened, the rule is the right one for it — this
                // event's recipients are simply not the audience it targets.
                return ['rule_id' => $rule->id, 'matched' => true, 'result' => null, 'error' => null, 'skipped' => $skipped];
            }
        }

        try {
            $result = $this->service->send($this->requestFor($rule, $eventKey, $fields, $userIds, $dedupKey, null, $channels));
        } catch (NotificationValidationException $e) {
            Log::channel('notifications')->warning('notifications.rule_send_invalid', [
                'rule_id' => $rule->id,
                'event' => $eventKey,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ]);

            return ['rule_id' => $rule->id, 'matched' => true, 'result' => null, 'error' => $e->reason, 'skipped' => $skipped];
        }

        return ['rule_id' => $rule->id, 'matched' => true, 'result' => $result, 'error' => null, 'skipped' => $skipped];
    }

    /** Apply one operator, comparing as the field's catalog type. */
    public static function compare(string $type, string $op, mixed $actual, mixed $expected): bool
    {
        $cast = function (mixed $v) use ($type) {
            if ($v === null) {
                return null;
            }

            if ($type === EventCatalog::TYPE_INT) {
                return is_numeric($v) ? (int) $v : null;
            }

            return is_scalar($v) ? (string) $v : null;
        };

        $a = $cast($actual);

        if (in_array($op, EventCatalog::LIST_OPERATORS, true)) {
            $list = array_map($cast, is_array($expected) ? $expected : [$expected]);
            $in = $a !== null && in_array($a, $list, true);

            return $op === 'in' ? $in : !$in;
        }

        $e = $cast($expected);

        return match ($op) {
            'eq' => $a !== null && $a === $e,
            'neq' => $a !== $e,
            'gt' => $a !== null && $e !== null && $a > $e,
            'gte' => $a !== null && $e !== null && $a >= $e,
            'lt' => $a !== null && $e !== null && $a < $e,
            'lte' => $a !== null && $e !== null && $a <= $e,
            default => false,
        };
    }
}
