<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin API Key
    |--------------------------------------------------------------------------
    |
    | This key is used to authenticate admin API requests via the X-Admin-Key
    | header. Generate a secure random key for production use.
    |
    */
    'api_key' => env('ADMIN_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Admin Emails
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of email addresses that have admin privileges.
    | Users with these emails can access admin routes when authenticated.
    |
    */
    'emails' => env('ADMIN_EMAILS', ''),

    /*
    |--------------------------------------------------------------------------
    | Admin App URL
    |--------------------------------------------------------------------------
    |
    | Public URL of the Next.js admin app. Used to build the sign-in link in
    | the invitation e-mail a new teacher or admin receives. Shares the env
    | var with generation.capture_base_url — same deployed app.
    |
    */
    'app_url' => env('ADMIN_APP_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Team Invite Lifetime
    |--------------------------------------------------------------------------
    |
    | How many days a teacher/admin invite stays open (BR-XW6CWV). Accepting it
    | means signing in to the admin once; after this many days without that,
    | the invite reads as expired and the admin has to resend it.
    |
    */
    'invite_ttl_days' => (int) env('ADMIN_INVITE_TTL_DAYS', 7),
];
