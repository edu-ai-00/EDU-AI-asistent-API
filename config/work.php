<?php

// Active work-time tracking tuning (BR-9SAH2R).
return [
    // Two consecutive signals more than this many seconds apart start a new
    // work session. Matches the ELO session bucketing (30 min).
    'session_gap_seconds' => (int) env('WORK_SESSION_GAP_SECONDS', 30 * 60),

    // Per-gap credit cap: a single gap between signals credits at most this
    // many seconds of active work, so idle time between sparse signals does
    // not inflate the total.
    'active_cap_seconds' => (int) env('WORK_ACTIVE_CAP_SECONDS', 90),

    // Timezone used to bucket work time into calendar days for reporting.
    'timezone' => env('WORK_TIMEZONE', 'Europe/Prague'),
];
