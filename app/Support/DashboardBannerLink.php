<?php

namespace App\Support;

/**
 * What a dashboard banner's tap target may be (BR-SAWX2U).
 *
 * The same vocabulary notification deep links use, plus the two targets only
 * the dashboard needs: a course already synced to the device
 * (`/field-course/{slug}`) and a chat with one mentor (`/chat/{persona}`).
 *
 * Validated on save so an admin gets a Czech error instead of a banner that
 * silently lands on the dashboard — the app's fallback for a link it cannot
 * read.
 */
final class DashboardBannerLink
{
    /** Single-segment paths, as the app's resolveNotificationLink reads them. */
    public const SIMPLE = [
        '/dash',
        '/news',
        '/courses',
        '/library',
        '/chat',
        '/practice',
        '/quiz',
        '/games',
        '/tools',
        '/profile',
        '/settings/notifications',
    ];

    /** Two-segment prefixes that take an id after them. */
    public const PREFIXED = ['/course', '/pin', '/field-course', '/chat'];

    /** Mentors a `/chat/{persona}` link may open. */
    public const PERSONAS = [
        'ai_teacher',
        'math_mentor',
        'study_coach',
        'language_mentor',
        'skolnik',
        'cermatik',
    ];

    private function __construct() {}

    /** Is `$link` something the app can open? An empty link (no tap) is fine. */
    public static function isValid(?string $link): bool
    {
        $trimmed = trim((string) $link);
        if ($trimmed === '') {
            return true;
        }
        if (! str_starts_with($trimmed, '/')) {
            return false;
        }
        if (in_array($trimmed, self::SIMPLE, true)) {
            return true;
        }

        $segments = array_values(array_filter(explode('/', $trimmed), fn ($s) => $s !== ''));
        if (count($segments) !== 2) {
            return false;
        }

        [$prefix, $id] = $segments;
        if (! in_array('/'.$prefix, self::PREFIXED, true)) {
            return false;
        }

        return $prefix === 'chat'
            ? in_array($id, self::PERSONAS, true)
            : $id !== '';
    }

    /** The paths and prefixes the admin UI offers, for the meta endpoint. */
    public static function catalog(): array
    {
        return [
            'simple' => self::SIMPLE,
            'prefixed' => self::PREFIXED,
            'personas' => self::PERSONAS,
        ];
    }
}
