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
];
