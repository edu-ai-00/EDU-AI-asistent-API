<?php

return [
    /*
    | Age of digital consent per ISO-3166 country. Anything below this needs a
    | verified guardian consent before the child can use the app.
    */
    'age_thresholds' => [
        'CZ' => 15,
        'SK' => 16,
    ],
    'default_age_threshold' => 15,

    // How long the emailed 6-char confirmation code stays valid.
    'confirm_code_ttl_hours' => (int) env('CONSENT_CODE_TTL_HOURS', 72),

    // How long a guardian magic-link stays usable.
    'manage_token_ttl_hours' => (int) env('CONSENT_MANAGE_TTL_HOURS', 24),

    // Failed confirmation attempts before the code is burned.
    'max_confirm_attempts' => 5,

    // Child accounts created per guardian on first confirmation.
    'children_per_guardian' => 3,

    // Bumped whenever the legal wording changes; stored on every consent row.
    'text_version' => env('CONSENT_TEXT_VERSION', '2026-08-13'),

    // Grace period between revocation and irreversible data erasure.
    'purge_after_days' => 30,

    // Public web build that hosts /consent/confirm and /consent/manage.
    'web_base_url' => env('CONSENT_WEB_URL', 'https://app.edu-ai.eu'),
];
