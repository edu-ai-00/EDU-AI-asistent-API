<?php

/*
| Laravel Nightwatch. Published from the package so the defaults below are
| versioned with the code instead of living only in Railway variables — every
| environment that gets a NIGHTWATCH_TOKEN gets the same redaction rules.
| Nothing here does anything without a token: start.sh only runs the agent
| when NIGHTWATCH_TOKEN is set, and without the agent records go nowhere.
|
| Each Nightwatch environment (stage, production) has its own token.
*/

return [
    'enabled' => env('NIGHTWATCH_ENABLED', true),
    'token' => env('NIGHTWATCH_TOKEN'),

    // Ties every record to the deploy that served it, so a regression lines
    // up with the commit that caused it. Railway injects the commit SHA at
    // runtime; start.sh sends the matching deploy marker.
    'deployment' => env('NIGHTWATCH_DEPLOY', env('RAILWAY_GIT_COMMIT_SHA')),

    // The container hostname changes on every Railway deploy, which would
    // show up in Nightwatch as a new server each time.
    'server' => env('NIGHTWATCH_SERVER', env('RAILWAY_SERVICE_NAME', (string) gethostname())),

    'capture_exception_source_code' => env('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true),

    // The package only ever attaches a payload to a request that ended in a
    // 500, so this costs nothing on healthy traffic and shows what the client
    // actually sent when something breaks.
    'capture_request_payload' => env('NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD', true),

    // Credentials that arrive in request bodies: email/consent/login codes,
    // OAuth id tokens (Google/Microsoft `id_token`, Apple `identity_token`).
    'redact_payload_fields' => explode(',', env(
        'NIGHTWATCH_REDACT_PAYLOAD_FIELDS',
        '_token,password,password_confirmation,code,login_code,id_token,identity_token,authorization_code,access_token,refresh_token,token',
    )),

    // Request headers are recorded on EVERY request, not only on errors. The
    // admin panel proxy sends X-Admin-Key alongside the bearer token, so it
    // has to be redacted here or the key is stored in Nightwatch in clear.
    'redact_headers' => explode(',', env(
        'NIGHTWATCH_REDACT_HEADERS',
        'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN,X-Admin-Key',
    )),

    'sampling' => [
        'requests' => env('NIGHTWATCH_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('NIGHTWATCH_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('NIGHTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        'ignore_cache_events' => env('NIGHTWATCH_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('NIGHTWATCH_IGNORE_MAIL', false),
        'ignore_notifications' => env('NIGHTWATCH_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('NIGHTWATCH_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('NIGHTWATCH_IGNORE_QUERIES', false),
        'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
    ],

    'ingest' => [
        'uri' => env('NIGHTWATCH_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('NIGHTWATCH_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('NIGHTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('NIGHTWATCH_INGEST_EVENT_BUFFER', 500),
    ],
];
