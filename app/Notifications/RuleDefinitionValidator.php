<?php

namespace App\Notifications;

use App\Models\NotificationRule;
use App\Models\NotificationType;

/**
 * Validates a rule definition (NOTIF-9, spec §3.2) and guards activation.
 *
 * Errors are `{path, message}` with Czech messages, where path points into
 * the JSON (`when.event`, `if[0].field`, `then.channels`). A definition
 * arriving as a string is parsed first; broken JSON is one error at path
 * `$` with the line and column of the first syntax error.
 *
 * validate() answers "is this a well-formed rule?"; activationErrors() adds
 * what an active rule also needs (an active type, no identical active twin);
 * systemLockErrors() what a system rule may not change.
 */
class RuleDefinitionValidator
{
    public const MAX_DELAY_MINUTES = 10080;

    private const TOP_KEYS = ['when', 'if', 'then'];
    private const WHEN_KEYS = ['event'];
    private const THEN_KEYS = ['type', 'target_role', 'channels', 'delay_minutes'];
    private const CONDITION_KEYS = ['field', 'op', 'value'];

    /**
     * @return array{definition: ?array, errors: list<array{path: string, message: string, line?: int, column?: int, code?: string}>}
     */
    public function validate(mixed $definition): array
    {
        [$parsed, $errors] = $this->parse($definition);

        if ($parsed === null) {
            return ['definition' => null, 'errors' => $errors];
        }

        return ['definition' => $parsed, 'errors' => $this->check($parsed)];
    }

    /** @return list<array{path: string, message: string}> */
    public function errors(mixed $definition): array
    {
        return $this->validate($definition)['errors'];
    }

    /**
     * Everything validate() checks, plus the activation guard (NOTIF-9 D):
     * the type must be active and no other active rule may have the same
     * event, type and identical conditions.
     *
     * @return list<array{path: string, message: string, code?: string}>
     */
    public function activationErrors(mixed $definition, ?int $ignoreRuleId = null): array
    {
        ['definition' => $parsed, 'errors' => $errors] = $this->validate($definition);

        if ($parsed === null || $errors !== []) {
            return $errors;
        }

        $type = NotificationType::where('key', $parsed['then']['type'])->first();
        if ($type !== null && !$type->is_active) {
            $errors[] = [
                'path' => 'then.type',
                'message' => sprintf('Typ notifikace „%s“ je vypnutý, pravidlo proto nelze aktivovat.', $type->key),
                'code' => 'type_inactive',
            ];
        }

        $conditions = self::normalizeConditions((array) ($parsed['if'] ?? []));

        $twins = NotificationRule::query()
            ->where('is_active', true)
            ->where('event_key', $parsed['when']['event'])
            ->where('notification_type_id', $type?->id)
            ->when($ignoreRuleId !== null, fn ($q) => $q->whereKeyNot($ignoreRuleId))
            ->get();

        foreach ($twins as $twin) {
            if (self::normalizeConditions($twin->conditions()) === $conditions) {
                $errors[] = [
                    'path' => '$',
                    'message' => sprintf(
                        'Aktivní pravidlo „%s“ (#%d) už má stejnou událost, typ i podmínky.',
                        $twin->name,
                        $twin->id,
                    ),
                    'code' => 'conflict',
                ];

                break;
            }
        }

        return $errors;
    }

    /**
     * A system rule keeps its event and its type — only conditions,
     * channels, role narrowing and delay are the admin's to change.
     *
     * @return list<array{path: string, message: string}>
     */
    public function systemLockErrors(NotificationRule $rule, mixed $definition): array
    {
        if (!$rule->is_system) {
            return [];
        }

        [$parsed] = $this->parse($definition);
        if ($parsed === null) {
            return [];
        }

        $errors = [];

        if (($parsed['when']['event'] ?? null) !== $rule->eventKey()) {
            $errors[] = [
                'path' => 'when.event',
                'message' => sprintf('Systémové pravidlo musí reagovat na událost %s.', $rule->eventKey()),
            ];
        }

        if (($parsed['then']['type'] ?? null) !== $rule->typeKey()) {
            $errors[] = [
                'path' => 'then.type',
                'message' => sprintf('Systémové pravidlo musí posílat typ %s.', $rule->typeKey()),
            ];
        }

        return $errors;
    }

    /**
     * Conditions in a canonical order, for comparing two rules.
     *
     * @param  array<int, mixed>  $conditions
     * @return list<string>
     */
    public static function normalizeConditions(array $conditions): array
    {
        $normalised = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $value = $condition['value'] ?? null;
            if (is_array($value)) {
                $value = array_values($value);
                sort($value);
            }

            $normalised[] = json_encode([
                (string) ($condition['field'] ?? ''),
                (string) ($condition['op'] ?? ''),
                $value,
            ], JSON_UNESCAPED_UNICODE);
        }

        sort($normalised);

        return $normalised;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Parsing
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @return array{0: ?array, 1: list<array{path: string, message: string, line?: int, column?: int}>}
     */
    public function parse(mixed $definition): array
    {
        if (is_string($definition)) {
            $decoded = json_decode($definition, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [null, [$this->jsonError($definition)]];
            }

            $definition = $decoded;
        }

        if (!is_array($definition) || ($definition !== [] && array_is_list($definition))) {
            return [null, [['path' => '$', 'message' => 'Definice pravidla musí být objekt JSON s klíči when, if a then.']]];
        }

        return [$definition, []];
    }

    /** @return array{path: string, message: string, line: int, column: int} */
    private function jsonError(string $json): array
    {
        try {
            JsonSyntax::check($json);
            // The scanner found nothing (depth, encoding) — PHP's word for it.
            $offset = 0;
            $detail = json_last_error_msg();
        } catch (JsonSyntaxError $e) {
            $offset = $e->offset;
            $detail = $e->getMessage();
        }

        [$line, $column] = JsonSyntax::lineAndColumn($json, $offset);

        return [
            'path' => '$',
            'message' => sprintf('Neplatný JSON na řádku %d, sloupci %d: %s', $line, $column, $detail),
            'line' => $line,
            'column' => $column,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Structure
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{path: string, message: string}>
     */
    private function check(array $definition): array
    {
        $errors = [];

        foreach (array_keys($definition) as $key) {
            if (!in_array($key, self::TOP_KEYS, true)) {
                $errors[] = $this->error((string) $key, sprintf('Neznámý klíč „%s“. Povolené jsou when, if a then.', $key));
            }
        }

        $event = $this->checkWhen($definition, $errors);
        $this->checkConditions($definition, $event, $errors);
        $this->checkThen($definition, $errors);

        return $errors;
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkWhen(array $definition, array &$errors): ?string
    {
        $when = $definition['when'] ?? null;

        if (!is_array($when) || ($when !== [] && array_is_list($when))) {
            $errors[] = $this->error('when', 'Chybí část „when“ s událostí, např. {"event": "course.completed"}.');

            return null;
        }

        foreach (array_keys($when) as $key) {
            if (!in_array($key, self::WHEN_KEYS, true)) {
                $errors[] = $this->error('when.' . $key, sprintf('Neznámý klíč „%s“. V části when je jen event.', $key));
            }
        }

        $event = $when['event'] ?? null;

        if (!is_string($event) || $event === '') {
            $errors[] = $this->error('when.event', 'Chybí událost (when.event).');

            return null;
        }

        if (!EventCatalog::has($event)) {
            $errors[] = $this->error('when.event', sprintf('Událost „%s“ neexistuje.', $event));

            return null;
        }

        return $event;
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkConditions(array $definition, ?string $event, array &$errors): void
    {
        if (!array_key_exists('if', $definition) || $definition['if'] === null) {
            return;
        }

        $conditions = $definition['if'];

        if (!is_array($conditions) || ($conditions !== [] && !array_is_list($conditions))) {
            $errors[] = $this->error('if', 'Podmínky (if) musí být seznam, např. [{"field": "percent", "op": "eq", "value": 50}].');

            return;
        }

        $fields = $event !== null ? EventCatalog::fields($event) : null;

        foreach ($conditions as $i => $condition) {
            $path = "if[{$i}]";

            if (!is_array($condition) || ($condition !== [] && array_is_list($condition))) {
                $errors[] = $this->error($path, 'Podmínka musí být objekt s klíči field, op a value.');

                continue;
            }

            foreach (array_keys($condition) as $key) {
                if (!in_array($key, self::CONDITION_KEYS, true)) {
                    $errors[] = $this->error("{$path}.{$key}", sprintf('Neznámý klíč „%s“. Podmínka má jen field, op a value.', $key));
                }
            }

            $field = $condition['field'] ?? null;
            $op = $condition['op'] ?? null;

            if (!is_string($field) || $field === '') {
                $errors[] = $this->error("{$path}.field", 'Chybí pole (field).');

                continue;
            }

            if (!is_string($op) || !array_key_exists($op, EventCatalog::OPERATORS)) {
                $errors[] = $this->error("{$path}.op", sprintf(
                    'Neznámý operátor „%s“. Povolené: %s.',
                    is_scalar($op) ? (string) $op : '',
                    implode(', ', array_keys(EventCatalog::OPERATORS)),
                ));

                continue;
            }

            if (!array_key_exists('value', $condition)) {
                $errors[] = $this->error("{$path}.value", 'Chybí hodnota (value).');

                continue;
            }

            if (RecipientCatalog::isRecipientField($field)) {
                // A recipient property means the same whatever the event is,
                // so it is checked even when the event itself is unknown.
                if (!RecipientCatalog::has($field)) {
                    $errors[] = $this->error("{$path}.field", sprintf(
                        'Vlastnost příjemce „%s“ neexistuje. Povolené: %s.',
                        $field,
                        implode(', ', array_keys(RecipientCatalog::all())),
                    ));

                    continue;
                }

                $this->checkValue($path, RecipientCatalog::fields()[$field], $op, $condition['value'], $errors);

                continue;
            }

            if ($fields === null) {
                // Event unknown — already reported; field names cannot be checked.
                continue;
            }

            if (!array_key_exists($field, $fields)) {
                $errors[] = $this->error("{$path}.field", sprintf('Pole „%s“ událost %s nemá.', $field, $event));

                continue;
            }

            $this->checkValue($path, $fields[$field], $op, $condition['value'], $errors);
        }
    }

    /**
     * @param  array{type: string, enum?: list<string>}  $field
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function checkValue(string $path, array $field, string $op, mixed $value, array &$errors): void
    {
        if (in_array($op, EventCatalog::NUMERIC_OPERATORS, true) && $field['type'] !== EventCatalog::TYPE_INT) {
            $errors[] = $this->error("{$path}.op", sprintf('Operátor „%s“ lze použít jen u číselného pole.', $op));

            return;
        }

        if (in_array($op, EventCatalog::LIST_OPERATORS, true)) {
            if (!is_array($value) || $value === [] || !array_is_list($value)) {
                $errors[] = $this->error("{$path}.value", sprintf('Operátor „%s“ vyžaduje neprázdný seznam hodnot, např. [7, 30].', $op));

                return;
            }

            foreach ($value as $j => $item) {
                $message = $this->valueError($field, $item);
                if ($message !== null) {
                    $errors[] = $this->error("{$path}.value[{$j}]", $message);
                }
            }

            return;
        }

        if (is_array($value)) {
            $errors[] = $this->error("{$path}.value", sprintf('Operátor „%s“ porovnává s jednou hodnotou, ne se seznamem.', $op));

            return;
        }

        $message = $this->valueError($field, $value);
        if ($message !== null) {
            $errors[] = $this->error("{$path}.value", $message);
        }
    }

    /** @param  array{type: string, enum?: list<string>}  $field */
    private function valueError(array $field, mixed $value): ?string
    {
        return match ($field['type']) {
            EventCatalog::TYPE_INT => is_int($value) ? null : 'Hodnota musí být celé číslo.',
            EventCatalog::TYPE_ENUM => is_string($value) && in_array($value, $field['enum'] ?? [], true)
                ? null
                : sprintf(
                    'Hodnota „%s“ není povolená. Povolené: %s.',
                    is_scalar($value) ? (string) $value : '',
                    implode(', ', $field['enum'] ?? []),
                ),
            default => is_string($value) || is_int($value) ? null : 'Hodnota musí být text.',
        };
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkThen(array $definition, array &$errors): void
    {
        $then = $definition['then'] ?? null;

        if (!is_array($then) || ($then !== [] && array_is_list($then))) {
            $errors[] = $this->error('then', 'Chybí část „then“ s typem notifikace, např. {"type": "course_completed"}.');

            return;
        }

        foreach (array_keys($then) as $key) {
            if (!in_array($key, self::THEN_KEYS, true)) {
                $errors[] = $this->error('then.' . $key, sprintf(
                    'Neznámý klíč „%s“. Povolené: %s.',
                    $key,
                    implode(', ', self::THEN_KEYS),
                ));
            }
        }

        $typeKey = $then['type'] ?? null;
        $type = is_string($typeKey) && $typeKey !== '' ? NotificationType::where('key', $typeKey)->first() : null;

        if (!is_string($typeKey) || $typeKey === '') {
            $errors[] = $this->error('then.type', 'Chybí typ notifikace (then.type).');
        } elseif ($type === null) {
            $errors[] = $this->error('then.type', sprintf('Typ notifikace „%s“ neexistuje.', $typeKey));
        }

        if (array_key_exists('target_role', $then)) {
            $role = $then['target_role'];

            if (!is_string($role) || !in_array($role, NotificationType::ROLES, true)) {
                $errors[] = $this->error('then.target_role', 'Cílová role musí být student, teacher nebo all.');
            } elseif ($type !== null && $role !== $type->target_role && $type->target_role !== 'all') {
                $errors[] = $this->error('then.target_role', sprintf(
                    'Typ %s je určen pro roli %s; pravidlo může roli jen zúžit z „all“ na jednu roli.',
                    $type->key,
                    $type->target_role,
                ));
            }
        }

        if (array_key_exists('channels', $then)) {
            $channels = $then['channels'];
            $allowed = $type?->channelList() ?? NotificationType::CHANNELS;

            if (!is_array($channels) || $channels === [] || !array_is_list($channels)) {
                $errors[] = $this->error('then.channels', 'Kanály musí být neprázdný seznam, např. ["push", "feed"].');
            } else {
                foreach ($channels as $j => $channel) {
                    if (!is_string($channel) || !in_array($channel, NotificationType::CHANNELS, true)) {
                        $errors[] = $this->error("then.channels[{$j}]", 'Neznámý kanál. Povolené: push, feed.');
                    } elseif (!in_array($channel, $allowed, true)) {
                        $errors[] = $this->error("then.channels[{$j}]", sprintf(
                            'Typ %s kanál „%s“ nepovoluje (povolené: %s).',
                            $type?->key,
                            $channel,
                            implode(', ', $allowed),
                        ));
                    }
                }
            }
        }

        if (array_key_exists('delay_minutes', $then)) {
            $delay = $then['delay_minutes'];

            if (!is_int($delay) || $delay < 0 || $delay > self::MAX_DELAY_MINUTES) {
                $errors[] = $this->error('then.delay_minutes', sprintf(
                    'Zpoždění musí být celé číslo od 0 do %d minut.',
                    self::MAX_DELAY_MINUTES,
                ));
            }
        }
    }

    /** @return array{path: string, message: string} */
    private function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
