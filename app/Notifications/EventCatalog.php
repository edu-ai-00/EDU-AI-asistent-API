<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;

/**
 * The domain events a notification rule can react to (NOTIF-7, spec §3.3).
 *
 * The catalog is code, not data: every event here has a producer somewhere
 * in the app, and a rule may only name events and fields listed here
 * (RuleDefinitionValidator). The admin reads it through
 * GET /api/admin/notifications/meta.
 *
 * Field types: int, string, enum (with `enum` values). Every field listed
 * is required unless it says otherwise.
 *
 * `dedup` is the template of the event's deduplication key: `{field}` takes
 * the field's value, `{date}` today's date and `{week}` the ISO week, both in
 * config('notifications.default_timezone'). The rule engine prefixes it with
 * the notification type, so two rules sending different types for the same
 * event never deduplicate each other.
 */
class EventCatalog
{
    public const TYPE_INT = 'int';
    public const TYPE_STRING = 'string';
    public const TYPE_ENUM = 'enum';

    public const OPERATORS = [
        'eq' => 'je rovno',
        'neq' => 'není rovno',
        'gt' => 'je větší než',
        'gte' => 'je větší nebo rovno',
        'lt' => 'je menší než',
        'lte' => 'je menší nebo rovno',
        'in' => 'je jedno z',
        'not_in' => 'není žádné z',
    ];

    /** Operators that only make sense on numbers. */
    public const NUMERIC_OPERATORS = ['gt', 'gte', 'lt', 'lte'];

    /** Operators whose value is a list. */
    public const LIST_OPERATORS = ['in', 'not_in'];

    /**
     * Template values derived from event fields for the texts, e.g. the
     * Czech word for a goal period. Merged into the payload by the rule
     * engine; never overrides a field the event already carries.
     */
    private const PERIOD_LABELS = ['daily' => 'denní', 'weekly' => 'týdenní'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'broadcast.sent' => [
                'label' => 'Odeslání rozesílky',
                'description' => 'Administrátor odeslal hromadnou zprávu školám nebo třídám.',
                'recipient' => 'Příjemci rozesílky podle snímku pořízeného při odeslání.',
                'producer' => 'Rozesílky (odeslání v administraci)',
                'fields' => [
                    'type_key' => ['type' => self::TYPE_STRING, 'label' => 'Typ notifikace rozesílky', 'required' => true],
                    'broadcast_id' => ['type' => self::TYPE_INT, 'label' => 'ID rozesílky', 'required' => true],
                    'title' => ['type' => self::TYPE_STRING, 'label' => 'Nadpis zprávy', 'required' => true],
                    'body' => ['type' => self::TYPE_STRING, 'label' => 'Text zprávy', 'required' => true],
                ],
                'dedup' => 'broadcast:{broadcast_id}',
            ],
            'broadcast.deadline_approaching' => [
                'label' => 'Blíží se termín rozesílky',
                'description' => 'Do termínu uvedeného v rozesílce zbývá 24 hodin.',
                'recipient' => 'Příjemci původní rozesílky.',
                'producer' => 'Plánovač (každých 15 minut)',
                'fields' => [
                    'broadcast_id' => ['type' => self::TYPE_INT, 'label' => 'ID rozesílky', 'required' => true],
                    'title' => ['type' => self::TYPE_STRING, 'label' => 'Nadpis zprávy', 'required' => true],
                    'deadline' => ['type' => self::TYPE_STRING, 'label' => 'Termín (d. m. Y H:i)', 'required' => true],
                    'hours_left' => ['type' => self::TYPE_INT, 'label' => 'Zbývající hodiny', 'required' => true],
                ],
                'dedup' => 'broadcast_deadline:{broadcast_id}',
            ],
            'habit.reminder_due' => [
                'label' => 'Čas na učení',
                'description' => 'Nastal čas učení, který si žák zvolil, a dnes se ještě neučil.',
                'recipient' => 'Žák, kterému právě nastal zvolený čas učení.',
                'producer' => 'Plánovač (každých 5 minut)',
                'fields' => [
                    'local_time' => ['type' => self::TYPE_STRING, 'label' => 'Místní čas (HH:MM)', 'required' => true],
                ],
                'dedup' => 'learning_reminder:{date}',
            ],
            'streak.milestone' => [
                'label' => 'Milník série',
                'description' => 'Série dní učení v řadě dosáhla 7, 30, 60 nebo 100 dní.',
                'recipient' => 'Žák, jehož série milníku dosáhla.',
                'producer' => 'Synchronizace statistik žáka',
                'fields' => [
                    'streak_days' => ['type' => self::TYPE_INT, 'label' => 'Dní v řadě', 'required' => true],
                ],
                'dedup' => 'streak:{streak_days}',
            ],
            'user.inactive' => [
                'label' => 'Neaktivita',
                'description' => 'Žák se několik dní nepřihlásil do aplikace.',
                'recipient' => 'Neaktivní žák.',
                'producer' => 'Plánovač (denně v 16:00 místního času)',
                'fields' => [
                    'days_inactive' => ['type' => self::TYPE_INT, 'label' => 'Dní bez aktivity', 'required' => true],
                ],
                'dedup' => 'inactive:{date}',
            ],
            'study.long_session' => [
                'label' => 'Dlouhé učení',
                'description' => 'Žák se učí bez přestávky déle než nastavený počet minut.',
                'recipient' => 'Žák, který se právě dlouho učí.',
                'producer' => 'Záznam pracovní doby (heartbeat)',
                'fields' => [
                    'minutes' => ['type' => self::TYPE_INT, 'label' => 'Délka učení (min)', 'required' => true],
                    'session_started_at' => ['type' => self::TYPE_STRING, 'label' => 'Začátek bloku učení', 'required' => true],
                ],
                'dedup' => 'long_session:{session_started_at}',
            ],
            'goal.completed' => [
                'label' => 'Splněný cíl',
                'description' => 'Žák splnil denní nebo týdenní cíl XP.',
                'recipient' => 'Žák, který cíl splnil.',
                'producer' => 'Synchronizace statistik žáka',
                'fields' => [
                    'period' => [
                        'type' => self::TYPE_ENUM,
                        'label' => 'Období',
                        'enum' => ['daily', 'weekly'],
                        'required' => true,
                    ],
                    'xp' => ['type' => self::TYPE_INT, 'label' => 'Získané XP', 'required' => true],
                    'target' => ['type' => self::TYPE_INT, 'label' => 'Cíl XP', 'required' => true],
                ],
                'dedup' => 'goal:{period}:{date}',
            ],
            'course.completed' => [
                'label' => 'Dokončený kurz',
                'description' => 'Žák dokončil kurz.',
                'recipient' => 'Žák, který kurz dokončil.',
                'producer' => 'Synchronizace kurzů žáka',
                'fields' => [
                    'course_id' => ['type' => self::TYPE_INT, 'label' => 'ID kurzu', 'required' => true],
                    'course_name' => ['type' => self::TYPE_STRING, 'label' => 'Název kurzu', 'required' => false],
                    'course_code' => ['type' => self::TYPE_STRING, 'label' => 'Kód kurzu', 'required' => false],
                ],
                'dedup' => 'course_completed:{course_id}',
            ],
            'course.progress_milestone' => [
                'label' => 'Postup v kurzu',
                'description' => 'Postup žáka v kurzu překročil milník (50 %).',
                'recipient' => 'Žák, jehož postup milník překročil.',
                'producer' => 'Synchronizace kurzů žáka',
                'fields' => [
                    'course_id' => ['type' => self::TYPE_INT, 'label' => 'ID kurzu', 'required' => true],
                    'course_name' => ['type' => self::TYPE_STRING, 'label' => 'Název kurzu', 'required' => false],
                    'course_code' => ['type' => self::TYPE_STRING, 'label' => 'Kód kurzu', 'required' => false],
                    'percent' => ['type' => self::TYPE_INT, 'label' => 'Milník (%)', 'required' => true],
                ],
                'dedup' => 'course_milestone:{course_id}:{percent}',
            ],
            'topic.mastered' => [
                'label' => 'Zvládnuté téma',
                'description' => 'Žák zvládl téma (dovednost).',
                'recipient' => 'Žák, který téma zvládl.',
                'producer' => 'Výpočet zvládnutí dovedností',
                'fields' => [
                    'topic_key' => ['type' => self::TYPE_STRING, 'label' => 'Klíč tématu', 'required' => true],
                    'topic_name' => ['type' => self::TYPE_STRING, 'label' => 'Název tématu', 'required' => false],
                ],
                'dedup' => 'topic_mastered:{topic_key}',
            ],
            'activation.daily' => [
                'label' => 'Denní aktivizace',
                'description' => 'Denní výzva pro žáka, který se dnes ještě neučil: kvíz, procvičování nebo hra.',
                'recipient' => 'Žák aktivní v posledních 14 dnech, který dnes ještě nebyl aktivní.',
                'producer' => 'Plánovač (denně v 15:30 místního času)',
                'fields' => [
                    'suggestion' => [
                        'type' => self::TYPE_ENUM,
                        'label' => 'Návrh',
                        'enum' => ['quiz', 'practice', 'game'],
                        'required' => true,
                    ],
                    'due_count' => ['type' => self::TYPE_INT, 'label' => 'Úloh k opakování', 'required' => false],
                    'game_name' => ['type' => self::TYPE_STRING, 'label' => 'Název hry', 'required' => false],
                    'days_since_activity' => ['type' => self::TYPE_INT, 'label' => 'Dní od poslední aktivity', 'required' => false],
                ],
                'dedup' => 'activation:{suggestion}:{date}',
            ],
        ];
    }

    public static function has(string $eventKey): bool
    {
        return array_key_exists($eventKey, self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $eventKey): ?array
    {
        return self::all()[$eventKey] ?? null;
    }

    /** @return array<string, array{type: string, label: string, required: bool, enum?: list<string>}> */
    public static function fields(string $eventKey): array
    {
        return self::all()[$eventKey]['fields'] ?? [];
    }

    /**
     * The catalog as the admin meta endpoint lists it.
     *
     * @return list<array<string, mixed>>
     */
    public static function forMeta(): array
    {
        $events = [];

        foreach (self::all() as $key => $event) {
            $fields = [];
            foreach ($event['fields'] as $name => $field) {
                $fields[] = ['name' => $name] + $field;
            }

            $events[] = [
                'key' => $key,
                'label' => $event['label'],
                'description' => $event['description'],
                'recipient' => $event['recipient'],
                'producer' => $event['producer'],
                'fields' => $fields,
                'dedup' => $event['dedup'],
            ];
        }

        return $events;
    }

    /**
     * Check event data against the catalog and cast it to the declared
     * types (an int field may arrive as a numeric string).
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: array<string, mixed>, 1: list<string>}  [normalised fields, errors]
     */
    public static function validateFields(string $eventKey, array $fields): array
    {
        $definition = self::get($eventKey);
        if ($definition === null) {
            return [[], ["unknown event {$eventKey}"]];
        }

        $errors = [];
        $normalised = [];

        foreach ($definition['fields'] as $name => $field) {
            $present = array_key_exists($name, $fields) && $fields[$name] !== null && $fields[$name] !== '';

            if (!$present) {
                if ($field['required'] ?? true) {
                    $errors[] = "missing field {$name}";
                }

                continue;
            }

            [$value, $error] = self::castValue($field, $fields[$name]);
            if ($error !== null) {
                $errors[] = "field {$name}: {$error}";

                continue;
            }

            $normalised[$name] = $value;
        }

        // Unknown extra fields are dropped, not fatal: a producer passing more
        // context than the catalog lists must not cost the user the notification.
        return [$normalised, $errors];
    }

    /**
     * Cast one value to a field's declared type.
     *
     * @param  array{type: string, enum?: list<string>}  $field
     * @return array{0: mixed, 1: ?string}  [value, error]
     */
    public static function castValue(array $field, mixed $value): array
    {
        switch ($field['type']) {
            case self::TYPE_INT:
                if (is_int($value)) {
                    return [$value, null];
                }
                if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                    return [(int) $value, null];
                }
                if (is_float($value) && floor($value) === $value) {
                    return [(int) $value, null];
                }

                return [null, 'expected int'];

            case self::TYPE_ENUM:
                if (is_string($value) && in_array($value, $field['enum'] ?? [], true)) {
                    return [$value, null];
                }

                return [null, 'expected one of ' . implode(', ', $field['enum'] ?? [])];

            default:
                if (is_scalar($value)) {
                    return [(string) $value, null];
                }

                return [null, 'expected string'];
        }
    }

    /** The event's dedup key for these fields (without the type prefix). */
    public static function dedupKey(string $eventKey, array $fields, ?\DateTimeInterface $now = null): string
    {
        $template = self::all()[$eventKey]['dedup'] ?? $eventKey . ':' . sha1(json_encode($fields));
        $now = CarbonImmutable::instance($now ?? now())
            ->setTimezone((string) config('notifications.default_timezone', 'Europe/Prague'));

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use ($fields, $now) {
            return match ($match[1]) {
                'date' => $now->format('Y-m-d'),
                'week' => $now->format('o-\WW'),
                default => is_scalar($fields[$match[1]] ?? null) ? (string) $fields[$match[1]] : '',
            };
        }, $template);
    }

    /**
     * Template variables derived from the event's fields.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, scalar>
     */
    public static function derivedVariables(string $eventKey, array $fields): array
    {
        $derived = [];

        if (isset($fields['period']) && is_string($fields['period'])) {
            $derived['period_label'] = self::PERIOD_LABELS[$fields['period']] ?? $fields['period'];
        }

        return $derived;
    }
}
