<?php

/*
|--------------------------------------------------------------------------
| Notification system (NOTIF-1 … NOTIF-10)
|--------------------------------------------------------------------------
|
| Contract: docs/superpowers/specs/2026-10-07-notification-system-design.md
|
| These are defaults. Per-type values (frequency, dedup window, channels,
| templates) live on notification_types and are edited by the admin; only
| what has no per-type home is read from here at send time.
|
*/

return [

    // Recipient-local time is computed in the user's own timezone
    // (notification_preferences.timezone); this is the fallback.
    'default_timezone' => env('NOTIFICATIONS_TIMEZONE', 'Europe/Prague'),

    // Recipient-local; the window may cross midnight.
    'quiet_hours' => ['start' => '20:00', 'end' => '07:00'],

    // Max notifications per category per recipient-local calendar day
    // (NOTIF-6 B / NOTIF-7 E). Mandatory types and test sends are exempt.
    'category_daily_caps' => ['aktivace' => 1],

    'push' => [
        // Validated against the samples when a template is saved, truncated
        // with "…" at send.
        'title_max' => 65,
        'body_max' => 180,
        'tries' => 3,
        'backoff' => [60, 300, 900],
    ],

    'feed' => ['title_max' => 120, 'body_max' => 1000, 'page_size' => 20],

    'goals' => ['daily_xp' => 50, 'weekly_xp' => 250],

    // topic.mastered: a GPF dimension of the student's ELO profile counts as
    // mastered once its rating reaches `elo` with at least `min_count`
    // rated answers behind it (EloProfileController emits on the crossing).
    'mastery' => ['elo' => 8.0, 'min_count' => 10],

    'inactivity_days' => 3,

    // notifications:activation only nudges students who used the app within
    // this many days (spec §3.5).
    'activation_active_within_days' => 14,

    'long_session_minutes' => 45,

    // Heartbeats further apart than this start a new study session
    // (App\Support\WorkSessionizer's default).
    'session_gap_minutes' => 30,

    'broadcast' => ['chunk' => 500, 'wide_threshold' => 200],

    // Admin history CSV export cap (NOTIF-8).
    'export_max_rows' => 50000,

    // notifications:queue-health warns above this many ready jobs on the
    // notifications queue.
    'queue_warning_pending' => 1000,

    // Every notification job runs here; start.sh's worker drains it before
    // `default`.
    'queue' => 'notifications',

    // FCM HTTP v1. Without both values push is "unavailable": the feed keeps
    // working and push decisions are logged as push_unavailable.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Service-account JSON, raw or base64-encoded.
        'credentials' => env('FCM_CREDENTIALS'),
    ],

];
