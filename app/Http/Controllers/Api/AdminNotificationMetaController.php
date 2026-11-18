<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationType;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationTargetActions;
use App\Notifications\QueueHealth;
use App\Notifications\RecipientCatalog;
use App\Notifications\RuleDefinitionValidator;
use App\Notifications\TemplateRenderer;
use Illuminate\Http\JsonResponse;

/**
 * What the notification admin needs to know about the system itself
 * (spec §5.2 "Catalog"): the event catalog the rule editor offers, the
 * enums with Czech labels, limits and defaults — and the queue's health.
 */
class AdminNotificationMetaController extends Controller
{
    private const CATEGORY_LABELS = [
        'provoz' => 'Provoz',
        'navyk' => 'Návyk',
        'pokrok' => 'Pokrok',
        'aktivace' => 'Aktivace',
    ];

    private const ROLE_LABELS = [
        'student' => 'Studenti',
        'teacher' => 'Učitelé',
        'all' => 'Všichni',
    ];

    private const CHANNEL_LABELS = [
        'push' => 'Push',
        'feed' => 'Novinky',
    ];

    private const TARGET_ACTION_LABELS = [
        NotificationTargetActions::LEARNING_ACTIVITY => 'Učení v aplikaci',
        NotificationTargetActions::QUIZ_COMPLETED => 'Dokončený kvíz',
        NotificationTargetActions::PRACTICE_REVIEWED => 'Procvičování',
        NotificationTargetActions::GAME_PLAYED => 'Zahraná hra',
    ];

    /**
     * The in-app destinations a deep link may use (spec §8 resolver).
     * Unknown paths fall back to /dash in the app.
     */
    private const DEEP_LINKS = [
        ['path' => '/dash', 'label' => 'Přehled'],
        ['path' => '/news', 'label' => 'Novinky'],
        ['path' => '/courses', 'label' => 'Moje kurzy'],
        ['path' => '/library', 'label' => 'Knihovna'],
        ['path' => '/course/{{course_code}}', 'label' => 'Konkrétní kurz (podle kódu)'],
        ['path' => '/practice', 'label' => 'Procvičování'],
        ['path' => '/quiz', 'label' => 'Kvíz'],
        ['path' => '/games', 'label' => 'Hry'],
        ['path' => '/profile', 'label' => 'Profil'],
        ['path' => '/settings/notifications', 'label' => 'Nastavení oznámení'],
    ];

    /**
     * GET /api/admin/notifications/meta
     */
    public function meta(): JsonResponse
    {
        $operators = [];
        foreach (EventCatalog::OPERATORS as $op => $label) {
            $operators[$op] = [
                'label' => $label,
                'types' => in_array($op, EventCatalog::NUMERIC_OPERATORS, true)
                    ? [EventCatalog::TYPE_INT]
                    : [EventCatalog::TYPE_INT, EventCatalog::TYPE_STRING, EventCatalog::TYPE_ENUM],
                'list' => in_array($op, EventCatalog::LIST_OPERATORS, true),
            ];
        }

        return response()->json([
            'data' => [
                'events' => EventCatalog::forMeta(),
                'recipient_fields' => RecipientCatalog::forMeta(),
                'operators' => $operators,
                'categories' => self::options(NotificationType::CATEGORIES, self::CATEGORY_LABELS),
                'roles' => self::options(NotificationType::ROLES, self::ROLE_LABELS),
                'channels' => self::options(NotificationType::CHANNELS, self::CHANNEL_LABELS),
                'target_actions' => self::options(NotificationTargetActions::ACTIONS, self::TARGET_ACTION_LABELS),
                'deep_links' => self::DEEP_LINKS,
                'limits' => TemplateRenderer::limits() + [
                    'broadcast_title' => 120,
                    'broadcast_body' => (int) config('notifications.feed.body_max', 1000),
                    'delay_minutes' => RuleDefinitionValidator::MAX_DELAY_MINUTES,
                    'export_rows' => (int) config('notifications.export_max_rows', 50000),
                ],
                'defaults' => [
                    'timezone' => (string) config('notifications.default_timezone'),
                    'quiet_hours' => (array) config('notifications.quiet_hours'),
                    'category_daily_caps' => (array) config('notifications.category_daily_caps'),
                    'goals' => (array) config('notifications.goals'),
                    'mastery' => (array) config('notifications.mastery'),
                    'inactivity_days' => (int) config('notifications.inactivity_days'),
                    'long_session_minutes' => (int) config('notifications.long_session_minutes'),
                    'broadcast' => [
                        'chunk' => (int) config('notifications.broadcast.chunk'),
                        'wide_threshold' => (int) config('notifications.broadcast.wide_threshold'),
                    ],
                    'feed_page_size' => (int) config('notifications.feed.page_size'),
                ],
            ],
        ]);
    }

    /**
     * GET /api/admin/notifications/queue-health
     */
    public function queueHealth(): JsonResponse
    {
        return response()->json(['data' => QueueHealth::snapshot()]);
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $keys, array $labels): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => $labels[$key] ?? $key], $keys);
    }
}
