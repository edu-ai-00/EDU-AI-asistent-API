<?php

namespace App\Notifications;

use App\Models\UserStats;

/**
 * Properties of the recipient a rule may put conditions on, next to the
 * event's own fields (BR-KSNDPF).
 *
 * An event's conditions answer "did this happen?"; these answer "to whom
 * does it apply?" — the dashboard's XP steps (intro kurz 0–30 XP,
 * přijímačky 31–150, hry 151+) written as a rule instead of being baked
 * into the app.
 *
 * The fields are namespaced `user.` for two reasons: the engine evaluates
 * them once per recipient rather than once per event, so it has to tell
 * them apart, and an event may carry a field of the same bare name
 * (goal.completed has an `xp` of its own — the XP that filled the goal,
 * not the XP the account has).
 */
class RecipientCatalog
{
    /** Everything the account has earned (user_stats.xp_points). */
    public const XP = 'user.xp';

    private const PREFIX = 'user.';

    /** @return array<string, array{type: string, label: string, description: string, required: bool}> */
    public static function all(): array
    {
        return [
            self::XP => [
                'type' => EventCatalog::TYPE_INT,
                'label' => 'Celkové XP příjemce',
                'description' => 'Všechna dosud získaná XP, stejné číslo jako na profilu žáka. Účet bez XP má 0.',
                'required' => true,
            ],
        ];
    }

    /** Whether the field names a recipient property instead of an event field. */
    public static function isRecipientField(string $field): bool
    {
        return str_starts_with($field, self::PREFIX);
    }

    public static function has(string $field): bool
    {
        return array_key_exists($field, self::all());
    }

    /** @return array<string, array{type: string, label: string, required: bool}> */
    public static function fields(): array
    {
        return self::all();
    }

    /**
     * The catalog as the admin meta endpoint lists it.
     *
     * @return list<array<string, mixed>>
     */
    public static function forMeta(): array
    {
        $fields = [];

        foreach (self::all() as $name => $field) {
            $fields[] = ['name' => $name] + $field;
        }

        return $fields;
    }

    /**
     * Split a rule's conditions into the ones about the event and the ones
     * about the recipient.
     *
     * @param  array<int, mixed>  $conditions
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}  [event, recipient]
     */
    public static function splitConditions(array $conditions): array
    {
        $event = [];
        $recipient = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $field = (string) ($condition['field'] ?? '');

            if (self::isRecipientField($field)) {
                $recipient[] = $condition;
            } else {
                $event[] = $condition;
            }
        }

        return [$event, $recipient];
    }

    /**
     * These properties for each of the recipients, in one query.
     *
     * A recipient without a user_stats row has earned nothing, which is
     * 0 XP rather than an unknown value: a rule written for the first XP
     * step has to reach a brand-new account.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, int>>
     */
    public static function valuesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $xp = UserStats::whereIn('user_id', $userIds)->pluck('xp_points', 'user_id');

        $values = [];
        foreach ($userIds as $userId) {
            $values[(int) $userId] = [self::XP => (int) ($xp[$userId] ?? 0)];
        }

        return $values;
    }
}
